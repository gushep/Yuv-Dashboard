<?php

namespace App\Services;

use App\Models\MondayConnection;
use App\Models\PlanningParticipant;
use App\Models\PlanningRound;
use App\Models\PlanningSession;
use App\Models\PlanningVote;
use App\Models\User;
use App\PlanningStatus;
use App\Support\SprintRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Planning poker da sprint, como uma mesa de jogo. O Maestro Scrum abre a mesa de uma sprint e joga no centro um card do
 * Backlog do quadro Tarefas; o time lê o escopo, cada pessoa joga a carta com as horas virada para baixo e, na revelação,
 * a média do time (soma das horas ÷ quantidade de cartas) aparece ao lado da estimativa da IA (o Tempo estimado do card).
 * A escolhida é gravada no card do Monday, com um comentário que registra as cartas.
 */
class PlanningService
{
    /** Quem consultou a mesa nos últimos segundos continua sentado nela. */
    public const PRESENCE_SECONDS = 20;

    /** Sprints mostradas no histórico da tela. */
    public const HISTORY_SPRINTS = 8;

    /** Marcas do escopo no comentário do card, da versão mais nova para a mais antiga. */
    private const SCOPE_MARKS = ['Escopo Funcional V3', 'Escopo Funcional V2', 'Escopo Funcional V1', 'Escopo V1', '[YUV -'];

    public function __construct(private MondayService $monday, private MondaySnapshotService $snapshots) {}

    /**
     * A mesa como cada pessoa vê. Consultar a mesa também senta a pessoa nela.
     *
     * @return array<string, mixed>
     */
    public function state(MondayConnection $connection, User $user): array
    {
        $session = $this->openSession();
        $canConduct = $user->canConductPlanning();
        if ($session === null) {
            return [
                'canConduct' => $canConduct, 'canEstimate' => false, 'me' => ['id' => $user->id, 'name' => $user->name],
                'session' => null, 'round' => null, 'seats' => [], 'estimated' => [], 'hand' => null, 'load' => [],
                'sprintOptions' => $canConduct ? $this->sprintOptions($connection) : [],
            ];
        }

        PlanningParticipant::updateOrCreate(['planning_session_id' => $session->id, 'user_id' => $user->id], ['last_seen_at' => now()]);
        $round = $session->rounds()->whereIn('status', PlanningStatus::open())->latest('id')->first();
        $votes = $round ? $round->votes()->get()->keyBy('user_id') : collect();
        $estimated = $session->rounds()->where('status', PlanningStatus::Closed)->orderBy('closed_at')->get();
        $seats = $this->seats($session, $round, $votes, $user);

        return [
            'canConduct' => $canConduct,
            'canEstimate' => $this->estimates($user, $session),
            'me' => ['id' => $user->id, 'name' => $user->name],
            'session' => ['id' => $session->id, 'sprint' => $session->sprint, 'sprintName' => $session->sprint_name, 'startedBy' => $session->starter?->name],
            'round' => $round ? [...$this->presentRound($round, $votes, $user), 'estimators' => collect($seats)->where('role', 'estimator')->count()] : null,
            'seats' => $seats,
            'estimated' => $estimated->map(fn (PlanningRound $closed): array => [
                'itemId' => $closed->item_id, 'name' => $closed->item_name, 'url' => $closed->item_url,
                'finalEstimate' => $closed->final_estimate, 'finalSource' => $closed->final_source,
                'average' => $closed->average, 'aiEstimate' => $closed->ai_estimate,
            ])->all(),
            'hand' => $canConduct ? $this->backlog($connection) : null,
            'load' => $this->load($connection, $session, $estimated),
            'sprintOptions' => [],
        ];
    }

    /**
     * Carga de cada pessoa na sprint planejada contra a capacidade: horas estimadas dos cards em que ela é responsável e
     * que já estão na sprint, mais as dos cards estimados nesta planning. Card com mais de um responsável divide as
     * horas igualmente. A capacidade é a da tela Capacity para a sprint, ou o padrão das Configurações (80h).
     *
     * @param  Collection<int, PlanningRound>  $estimated
     * @return list<array{name: string, inSprint: float, planned: float, total: float, capacity: float, percent: ?int}>
     */
    private function load(MondayConnection $connection, PlanningSession $session, Collection $estimated): array
    {
        $snapshot = $this->snapshots->latest($connection, PHP_INT_MAX);
        $settings = $connection->metricSettings();
        $excluded = array_map([SprintRules::class, 'normalize'], $settings['excludedPeople']);
        $overrides = $connection->capacity_overrides ?? [];
        $people = [];
        $add = function (string $owners, float $hours, string $key) use (&$people, $excluded): void {
            $names = array_values(array_filter(array_map('trim', explode(',', $owners)), fn (string $name): bool => $name !== '' && ! in_array(SprintRules::normalize($name), $excluded, true)));
            foreach ($names as $name) {
                $people[$name] ??= ['inSprint' => 0.0, 'planned' => 0.0];
                $people[$name][$key] += $hours / count($names);
            }
        };
        $inSprint = collect($snapshot['rows'] ?? [])
            ->filter(fn (array $row): bool => (int) ($row['Sprint'] ?? 0) === $session->sprint && ! SprintRules::isRemoved($row['Status'] ?? null));
        foreach ($inSprint as $row) {
            $add((string) ($row['Responsavel'] ?? ''), (float) ($row['EstH'] ?? 0), 'inSprint');
        }
        // Card estimado aqui que já entrou na sprint no Monday conta uma vez só, pela sprint.
        $sprintIds = $inSprint->pluck('Id')->all();
        foreach ($estimated as $round) {
            if (! in_array($round->item_id, $sprintIds, true)) {
                $add((string) $round->item_owner, (float) $round->final_estimate, 'planned');
            }
        }

        return collect($people)->map(function (array $hours, string $name) use ($overrides, $settings, $session): array {
            $capacity = isset($overrides["{$session->sprint}|{$name}"]) ? $overrides["{$session->sprint}|{$name}"] / 60 : (float) $settings['capacityHours'];
            $total = $hours['inSprint'] + $hours['planned'];

            return [
                'name' => $name, 'inSprint' => round($hours['inSprint'], 2), 'planned' => round($hours['planned'], 2),
                'total' => round($total, 2), 'capacity' => round($capacity, 2), 'percent' => $capacity > 0 ? (int) round($total / $capacity * 100) : null,
            ];
        })->sortByDesc('total')->values()->all();
    }

    /**
     * Últimas sprints com as tarefas e as horas planejadas (Tempo estimado) e executadas (Tempo real).
     *
     * @return list<array<string, mixed>>
     */
    public function history(MondayConnection $connection): array
    {
        $snapshot = $this->snapshots->latest($connection, PHP_INT_MAX);
        $today = SprintRules::today();
        $rows = collect($snapshot['rows'] ?? [])->reject(fn (array $row): bool => SprintRules::isRemoved($row['Status'] ?? null))->groupBy('Sprint');

        return collect($snapshot['sprints'] ?? [])
            ->filter(fn (array $sprint): bool => $sprint['start'] <= $today)
            ->sortByDesc('start')->take(self::HISTORY_SPRINTS)
            ->map(function (array $sprint) use ($rows): array {
                $tasks = collect($rows->get($sprint['number'], []))->map(fn (array $row): array => [
                    'id' => $row['Id'], 'url' => $row['Url'] ?? '', 'name' => $row['Nome'], 'type' => $row['Tipo'] ?? '',
                    'status' => $row['Status'] ?? '', 'owner' => $row['Responsavel'] ?? '',
                    'planned' => $row['EstH'] ?? null, 'executed' => $row['RealH'] ?? null,
                ])->values();

                return [
                    'number' => (int) $sprint['number'], 'name' => $sprint['name'], 'start' => $sprint['start'], 'end' => $sprint['end'],
                    'cards' => $tasks->count(),
                    'plannedHours' => round((float) $tasks->sum('planned'), 2),
                    'executedHours' => round((float) $tasks->sum('executed'), 2),
                    'tasks' => $tasks->all(),
                ];
            })->values()->all();
    }

    /** Abre a mesa de uma sprint que ainda não terminou. Só existe uma mesa aberta por vez. */
    public function openTable(MondayConnection $connection, User $user, int $sprint): PlanningSession
    {
        $option = collect($this->sprintOptions($connection))->firstWhere('number', $sprint)
            ?? throw new RuntimeException('Escolha uma sprint atual ou futura do cronograma.');

        return DB::transaction(function () use ($user, $option): PlanningSession {
            $open = PlanningSession::query()->where('status', PlanningSession::OPEN)->lockForUpdate()->first();
            if ($open !== null) {
                throw new RuntimeException("Já existe uma planning aberta: {$open->sprint_name}. Encerre antes de abrir outra.");
            }

            return PlanningSession::create(['sprint' => $option['number'], 'sprint_name' => $option['name'], 'status' => PlanningSession::OPEN, 'started_by' => $user->id]);
        });
    }

    /** Encerra a mesa; um card que ainda estava nela sai sem gravar nada. */
    public function closeTable(PlanningSession $session): void
    {
        if ($session->status !== PlanningSession::OPEN) {
            throw new RuntimeException('Esta planning já foi encerrada.');
        }
        DB::transaction(function () use ($session): void {
            $session->rounds()->whereIn('status', PlanningStatus::open())->update(['status' => PlanningStatus::Canceled, 'closed_at' => now()]);
            $session->update(['status' => PlanningSession::CLOSED, 'closed_at' => now()]);
        });
    }

    /**
     * Joga o card no centro da mesa para leitura. Lê agora no Monday a estimativa da IA (Tempo estimado) e o escopo mais
     * recente publicado no card.
     */
    public function playCard(MondayConnection $connection, PlanningSession $session, User $user, string $itemId): PlanningRound
    {
        if ($session->status !== PlanningSession::OPEN) {
            throw new RuntimeException('Esta planning já foi encerrada.');
        }
        $card = collect($this->backlog($connection)['items'])->firstWhere('Id', $itemId)
            ?? throw new RuntimeException('Este card não está no Backlog do quadro Tarefas (sem sprint e não iniciado).');
        $details = $this->cardDetails($connection, $itemId);

        return DB::transaction(function () use ($session, $user, $card, $details): PlanningRound {
            $open = PlanningRound::query()->where('planning_session_id', $session->id)->whereIn('status', PlanningStatus::open())->lockForUpdate()->first();
            if ($open !== null) {
                throw new RuntimeException("O card {$open->item_name} ainda está na mesa. Registre a estimativa ou tire o card antes de jogar outro.");
            }

            return PlanningRound::create([
                'planning_session_id' => $session->id,
                'item_id' => $card['Id'], 'item_name' => $card['Nome'], 'item_url' => $card['Url'] ?: null, 'item_owner' => ($card['Responsavel'] ?? '') ?: null,
                'ai_estimate' => $details['aiEstimate'], 'scope_title' => $details['scopeTitle'], 'scope_html' => $details['scopeHtml'],
                'status' => PlanningStatus::Reading, 'started_by' => $user->id,
            ]);
        });
    }

    /** Termina a leitura: cada pessoa passa a jogar a carta com as horas. */
    public function startEstimate(PlanningRound $round): void
    {
        $this->transition($round, PlanningStatus::Reading, PlanningStatus::Voting, 'A estimativa deste card já começou.');
    }

    /**
     * Joga ou troca a carta (horas) da pessoa, virada para baixo, enquanto a estimativa está aberta. Só o time de
     * desenvolvimento estima: quem conduz a mesa e quem só assiste não jogam carta. Sem horas (ou zero) é o voto vazio de
     * quem não tem contexto da demanda.
     */
    public function vote(PlanningRound $round, User $user, ?float $hours): void
    {
        if ($user->id === $round->session->started_by) {
            throw new RuntimeException('Quem conduz a planning não joga carta: a estimativa é do time.');
        }
        if (! $user->canEstimatePlanning()) {
            throw new RuntimeException('Só quem estima na planning joga carta. Peça a permissão "Estima na planning".');
        }
        DB::transaction(function () use ($round, $user, $hours): void {
            $round = PlanningRound::query()->lockForUpdate()->findOrFail($round->id);
            if ($round->status !== PlanningStatus::Voting) {
                throw new RuntimeException($round->status === PlanningStatus::Reading ? 'Aguarde o Maestro Scrum abrir a estimativa.' : 'As cartas deste card já foram reveladas.');
            }
            PlanningVote::updateOrCreate(['planning_round_id' => $round->id, 'user_id' => $user->id], ['hours' => $hours > 0 ? round($hours, 2) : null]);
        });
    }

    /**
     * Vira as cartas e calcula a média do time: soma das horas ÷ quantidade de pessoas que apontaram horas. Os votos
     * vazios aparecem, mas ficam fora da conta; sem nenhum apontamento, não há média.
     */
    public function reveal(PlanningRound $round): PlanningRound
    {
        return DB::transaction(function () use ($round): PlanningRound {
            $round = PlanningRound::query()->lockForUpdate()->findOrFail($round->id);
            if ($round->status !== PlanningStatus::Voting) {
                throw new RuntimeException('Só dá para revelar durante a estimativa.');
            }
            $votes = $round->votes()->pluck('hours');
            if ($votes->isEmpty()) {
                throw new RuntimeException('Ninguém jogou a carta ainda.');
            }
            $pointed = $votes->filter(fn (?float $hours): bool => $hours > 0);
            $round->update([
                'status' => PlanningStatus::Revealed,
                'average' => $pointed->isEmpty() ? null : round($pointed->sum() / $pointed->count(), 2),
                'revealed_at' => now(),
            ]);

            return $round;
        });
    }

    /**
     * Grava no card a estimativa escolhida pelo PO (a da IA ou a média do time) e comenta as cartas, a decisão e o que o
     * time discutiu. Se o Monday recusar a gravação, o card continua na mesa, revelado, para tentar de novo.
     */
    public function decide(MondayConnection $connection, PlanningRound $round, User $user, string $source, ?string $note = null): PlanningRound
    {
        $round->refresh();
        if ($round->status !== PlanningStatus::Revealed) {
            throw new RuntimeException('Revele as cartas antes de escolher a estimativa.');
        }
        if ($source === PlanningRound::SOURCE_AI && $round->ai_estimate === null) {
            throw new RuntimeException('O card não tem estimativa da IA: use a média do time.');
        }
        if ($source === PlanningRound::SOURCE_PLANNING && $round->average === null) {
            throw new RuntimeException('Ninguém do time apontou horas: use a estimativa da IA ou tire a carta da mesa.');
        }
        $estimate = $source === PlanningRound::SOURCE_AI ? $round->ai_estimate : $round->average;
        $this->monday->changeItemColumn((string) $connection->token, [(string) $connection->board_id => null], $round->item_id, $this->estimateColumn($connection), (string) $estimate);
        $this->snapshots->markStale();

        $round->update([
            'status' => PlanningStatus::Closed, 'final_estimate' => $estimate, 'final_source' => $source, 'decided_by' => $user->id,
            'decision_note' => filled($note) ? trim($note) : null, 'closed_at' => now(),
        ]);
        Log::info('Estimativa definida na planning poker.', ['item' => $round->item_id, 'estimate' => $estimate, 'source' => $source, 'user' => $user->email]);

        try {
            $this->monday->postComment((string) $connection->token, [(string) $connection->board_id], $round->item_id, $this->summary($round), $user->name);
        } catch (RuntimeException $exception) {
            report($exception);
        }

        return $round;
    }

    /** Tira o card da mesa sem gravar nada. */
    public function cancel(PlanningRound $round): void
    {
        $round->refresh();
        if (! $round->status->isOpen()) {
            throw new RuntimeException('Este card já saiu da mesa.');
        }
        $round->update(['status' => PlanningStatus::Canceled, 'closed_at' => now()]);
    }

    public function openSession(): ?PlanningSession
    {
        return PlanningSession::query()->where('status', PlanningSession::OPEN)->latest('id')->first();
    }

    private function transition(PlanningRound $round, PlanningStatus $from, PlanningStatus $to, string $message): void
    {
        DB::transaction(function () use ($round, $from, $to, $message): void {
            $round = PlanningRound::query()->lockForUpdate()->findOrFail($round->id);
            if ($round->status !== $from) {
                throw new RuntimeException($message);
            }
            $round->update(['status' => $to]);
        });
    }

    /**
     * Sprints que podem ser planejadas: a atual e as próximas do cronograma.
     *
     * @return list<array{number: int, name: string, start: string, end: string}>
     */
    private function sprintOptions(MondayConnection $connection): array
    {
        $snapshot = $this->snapshots->latest($connection, PHP_INT_MAX);
        $today = SprintRules::today();

        return collect($snapshot['sprints'] ?? [])
            ->filter(fn (array $sprint): bool => $sprint['end'] >= $today)
            ->sortBy('start')
            ->map(fn (array $sprint): array => ['number' => (int) $sprint['number'], 'name' => (string) $sprint['name'], 'start' => (string) $sprint['start'], 'end' => (string) $sprint['end']])
            ->values()->all();
    }

    /**
     * Cartas da mão do Maestro Scrum: os cards do Backlog do quadro Tarefas no último snapshot.
     *
     * @return array{items: list<array<string, mixed>>, available: bool, syncedAt: ?string}
     */
    private function backlog(MondayConnection $connection): array
    {
        $snapshot = $this->snapshots->latest($connection, PHP_INT_MAX);
        if (! array_key_exists('backlog', $snapshot)) {
            return ['items' => [], 'available' => false, 'syncedAt' => $snapshot['syncedAt'] ?? null];
        }
        $items = array_map(fn (array $card): array => array_intersect_key($card, array_flip(['Id', 'Url', 'Nome', 'Responsavel', 'Status', 'Prioridade', 'Tipo', 'Cliente'])), $snapshot['backlog']);

        return ['items' => $items, 'available' => true, 'syncedAt' => $snapshot['syncedAt'] ?? null];
    }

    /**
     * Tempo estimado atual e escopo mais recente do card, lidos no Monday.
     *
     * @return array{aiEstimate: ?float, scopeTitle: ?string, scopeHtml: ?string}
     */
    private function cardDetails(MondayConnection $connection, string $itemId): array
    {
        $query = 'query ($ids: [ID!]!, $columns: [String!]) { items(ids: $ids) { id board { id } column_values(ids: $columns) { text } updates(limit: 100) { body text_body created_at } } }';
        $item = $this->monday->query((string) $connection->token, $query, ['ids' => [$itemId], 'columns' => [$this->estimateColumn($connection)]])['items'][0] ?? null;
        if ($item === null || (string) ($item['board']['id'] ?? '') !== (string) $connection->board_id) {
            throw new RuntimeException('Card não encontrado no quadro Tarefas.');
        }
        $text = str_replace(',', '.', trim((string) ($item['column_values'][0]['text'] ?? '')));
        $scope = null;
        foreach (self::SCOPE_MARKS as $mark) {
            $scope = collect($item['updates'] ?? [])->first(fn (array $update): bool => str_contains((string) ($update['text_body'] ?? ''), $mark));
            if ($scope !== null) {
                $scope['mark'] = $mark;
                break;
            }
        }

        return [
            'aiEstimate' => is_numeric($text) ? (float) $text : null,
            'scopeTitle' => $scope ? (str_starts_with($scope['mark'], 'Escopo') ? $scope['mark'] : 'Escopo').' · publicado em '.Carbon::parse($scope['created_at'])->timezone('America/Sao_Paulo')->format('d/m/Y') : null,
            'scopeHtml' => $scope ? $this->sanitize((string) $scope['body']) : null,
        ];
    }

    /** HTML do escopo só com a formatação do texto: sem atributos, links, imagens ou scripts. */
    private function sanitize(string $html): string
    {
        $tags = ['h1', 'h2', 'h3', 'h4', 'p', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'br'];
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html);
        $html = strip_tags($html, $tags);

        return preg_replace('~<(/?)('.implode('|', $tags).')\b[^>]*>~i', '<$1$2>', $html);
    }

    private function estimateColumn(MondayConnection $connection): string
    {
        return (string) ($connection->column_map['EstH'] ?? '') ?: throw new RuntimeException('Mapeie a coluna Tempo estimado nas Configurações antes de usar a planning.');
    }

    /**
     * Card no centro da mesa. Durante a leitura e a estimativa, as horas de cada um e a estimativa da IA ficam escondidas.
     *
     * @param  Collection<int, PlanningVote>  $votes
     * @return array<string, mixed>
     */
    private function presentRound(PlanningRound $round, Collection $votes, User $user): array
    {
        $revealed = $round->status === PlanningStatus::Revealed;
        $pointed = $votes->filter(fn (PlanningVote $vote): bool => $vote->hours > 0);

        return [
            'id' => $round->id,
            'item' => ['id' => $round->item_id, 'name' => $round->item_name, 'url' => $round->item_url, 'owner' => $round->item_owner],
            'status' => $round->status->value,
            'hasScope' => $round->scope_html !== null,
            'votes' => $votes->count(),
            'myVoted' => $votes->has($user->id),
            'myVote' => $votes->get($user->id)?->hours,
            'pointed' => $revealed ? $pointed->count() : null,
            'empty' => $revealed ? $votes->count() - $pointed->count() : null,
            'sum' => $revealed ? round((float) $pointed->sum('hours'), 2) : null,
            'average' => $revealed ? $round->average : null,
            'aiEstimate' => $revealed ? $round->ai_estimate : null,
        ];
    }

    /**
     * Lugares da mesa: quem está com a tela aberta e quem já jogou a carta deste card. As horas só aparecem reveladas.
     *
     * @param  Collection<int, PlanningVote>  $votes
     * @return list<array<string, mixed>>
     */
    private function seats(PlanningSession $session, ?PlanningRound $round, Collection $votes, User $user): array
    {
        $present = $session->participants()->where('last_seen_at', '>=', now()->subSeconds(self::PRESENCE_SECONDS))->pluck('user_id');
        $ids = $present->merge($votes->keys())->unique();
        $revealed = $round?->status === PlanningStatus::Revealed;

        return User::query()->whereIn('id', $ids)->get(['id', 'name', 'screens', 'can_estimate_planning'])
            ->sortBy(fn (User $seat): string => mb_strtolower($seat->name))
            ->map(fn (User $seat): array => [
                'id' => $seat->id, 'name' => $seat->name, 'isMe' => $seat->id === $user->id,
                'role' => match (true) {
                    $seat->id === $session->started_by => 'conductor',
                    $this->estimates($seat, $session) => 'estimator',
                    default => 'watcher',
                },
                'played' => $votes->has($seat->id),
                'hours' => $revealed ? $votes->get($seat->id)?->hours : null,
            ])->values()->all();
    }

    /** O time de desenvolvimento estima; quem conduz a mesa não. */
    private function estimates(User $user, PlanningSession $session): bool
    {
        return $user->canEstimatePlanning() && $user->id !== $session->started_by;
    }

    /** Comentário publicado no card com as cartas e a decisão. */
    private function summary(PlanningRound $round): string
    {
        $votes = $round->votes()->with('user:id,name')->get()->sortBy(fn (PlanningVote $vote): string => mb_strtolower($vote->user->name));
        $pointed = $votes->filter(fn (PlanningVote $vote): bool => $vote->hours > 0);
        $empty = $votes->count() - $pointed->count();
        $hours = fn (?float $value): string => $value === null ? 'sem estimativa' : rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',').'h';
        $keptAi = $round->final_source === PlanningRound::SOURCE_AI;
        $other = $keptAi ? $round->average : $round->ai_estimate;
        $decision = $keptAi
            ? "manteve a estimativa da IA ({$hours($round->ai_estimate)}) em vez da média do time ({$hours($round->average)})"
            : 'ficou com a média do time ('.$hours($round->average).')'.($round->ai_estimate === null ? '; o card não tinha estimativa da IA' : " em vez da estimativa da IA ({$hours($round->ai_estimate)})");
        $gap = $other === null || abs($round->final_estimate - $other) < 0.005 ? ''
            : ', '.$hours(abs($round->final_estimate - $other)).($round->final_estimate > $other ? ' acima' : ' abaixo');

        return implode("\n", array_filter([
            "Planning poker da {$round->session->sprint_name}: estimativa definida em {$hours($round->final_estimate)}.",
            "Decisão do PO ({$round->decider?->name}): {$decision}{$gap}.",
            'Cartas do time: '.$votes->map(fn (PlanningVote $vote): string => $vote->user->name.' '.($vote->hours > 0 ? $hours($vote->hours) : 'vazio (sem contexto)'))->implode(', ').'.',
            $pointed->isEmpty() ? 'Média do time: ninguém apontou horas.'
                : "Média do time: {$hours($pointed->sum('hours'))} ÷ {$pointed->count()} pessoa(s) que apontaram = {$hours($round->average)}".($empty ? " ({$empty} voto(s) vazio(s) fora da conta)" : '').'.',
            $round->decision_note ? "O que o time discutiu: {$round->decision_note}" : null,
        ]));
    }

    /**
     * Escopo do card no centro da mesa, para o modal.
     *
     * @return array{name: string, url: ?string, title: ?string, html: ?string}
     */
    public function scope(PlanningRound $round): array
    {
        return ['name' => $round->item_name, 'url' => $round->item_url, 'title' => $round->scope_title, 'html' => $round->scope_html];
    }
}
