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
                'session' => null, 'round' => null, 'seats' => [], 'estimated' => [], 'hand' => null, 'load' => [], 'reserveHours' => 0.0,
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
            'session' => ['id' => $session->id, 'sprint' => $session->sprint, 'sprintName' => $session->sprint_name, 'startedBy' => $session->starter?->name, 'goal' => $session->goal,
                'elapsedSeconds' => $session->durationSeconds()],
            'round' => $round ? [...$this->presentRound($round, $votes, $user), 'estimators' => collect($seats)->where('role', 'estimator')->count()] : null,
            'seats' => $seats,
            'estimated' => $estimated->map(fn (PlanningRound $closed): array => [
                'itemId' => $closed->item_id, 'name' => $closed->item_name, 'url' => $closed->item_url,
                'finalEstimate' => $closed->final_estimate, 'finalSource' => $closed->final_source,
                'average' => $closed->average, 'aiEstimate' => $closed->ai_estimate,
            ])->all(),
            'hand' => $canConduct ? $this->backlog($connection) : null,
            'load' => $this->load($connection, $session->sprint, $estimated),
            // Reserva do Bombeiro descontada da capacidade Full stack, só depois de classificar os times (como no Capacity).
            'reserveHours' => $connection->teamRules()->configured() ? round($connection->supportReserveMinutes($session->sprint) / 60, 2) : 0.0,
            'sprintOptions' => [],
        ];
    }

    /**
     * Carga de cada pessoa na sprint planejada contra a capacidade: horas estimadas dos cards em que ela é responsável e
     * que já estão na sprint, mais as dos cards estimados nesta planning. Card com mais de um responsável divide as
     * horas igualmente. A capacidade é a da tela Capacity (a da sprint ou a herdada da última sprint salva), ou o padrão
     * das Configurações. Só entram as pessoas de Full stack ou Projetos; as classificadas aparecem mesmo sem carga.
     *
     * @param  Collection<int, PlanningRound>  $estimated
     * @return list<array{name: string, team: string, inSprint: float, planned: float, support: float, total: float, capacity: float, percent: ?int}>
     */
    private function load(MondayConnection $connection, int $sprint, Collection $estimated): array
    {
        $snapshot = $this->snapshots->latest($connection, PHP_INT_MAX);
        $teams = $connection->teamRules();
        $overrides = $connection->capacity_overrides ?? [];
        $people = [];
        $empty = ['inSprint' => 0.0, 'planned' => 0.0, 'support' => 0.0];
        foreach ($teams->members() as $member) {
            $people[$member['name']] = $empty;
        }
        $add = function (string $owners, float $hours, array $keys) use (&$people, $teams, $empty): void {
            $names = array_values(array_map([$teams, 'canonicalName'], array_filter(array_map('trim', explode(',', $owners)), fn (string $name): bool => $name !== '' && $teams->counts($name))));
            foreach ($names as $name) {
                $people[$name] ??= $empty;
                foreach ($keys as $key) {
                    $people[$name][$key] += $hours / count($names);
                }
            }
        };
        $inSprint = collect($snapshot['rows'] ?? [])
            ->filter(fn (array $row): bool => (int) ($row['Sprint'] ?? 0) === $sprint && ! SprintRules::isRemoved($row['Status'] ?? null));
        foreach ($inSprint as $row) {
            // Cards de Suporte entram na carga da pessoa e, à parte, em "support", que conta contra a reserva do Bombeiro.
            $isSupport = SprintRules::normalize($row['Tipo'] ?? '') === 'suporte';
            $add((string) ($row['Responsavel'] ?? ''), (float) ($row['EstH'] ?? 0), $isSupport ? ['inSprint', 'support'] : ['inSprint']);
        }
        // Card estimado aqui que já entrou na sprint no Monday conta uma vez só, pela sprint.
        $sprintIds = $inSprint->pluck('Id')->all();
        foreach ($estimated as $round) {
            if (! in_array($round->item_id, $sprintIds, true)) {
                $add((string) $round->item_owner, (float) $round->final_estimate, ['planned']);
            }
        }

        return collect($people)->map(function (array $hours, string $name) use ($overrides, $teams, $sprint): array {
            $capacity = $teams->capacityMinutes($overrides, $sprint, $name) / 60;
            $total = $hours['inSprint'] + $hours['planned'];

            return [
                'name' => $name, 'team' => (string) $teams->teamOf($name), 'inSprint' => round($hours['inSprint'], 2), 'planned' => round($hours['planned'], 2),
                'support' => round($hours['support'], 2), 'total' => round($total, 2), 'capacity' => round($capacity, 2), 'percent' => $capacity > 0 ? (int) round($total / $capacity * 100) : null,
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
        // Mesa mais recente de cada sprint e quantos cards ficaram estimados nela.
        $sessions = PlanningSession::query()->withCount(['rounds as estimated_count' => fn ($query) => $query->where('status', PlanningStatus::Closed)])
            ->with('participants.user')->orderBy('id')->get()->keyBy('sprint');

        return collect($snapshot['sprints'] ?? [])
            ->filter(fn (array $sprint): bool => $sprint['start'] <= $today)
            ->sortByDesc('start')->take(self::HISTORY_SPRINTS)
            ->map(function (array $sprint) use ($rows, $sessions, $today): array {
                $session = $sessions->get((int) $sprint['number']);
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
                    // Em andamento: dá para entrar (ou reabrir) a mesa até a sprint terminar; encerrada: só ver como foi.
                    'current' => $sprint['end'] >= $today,
                    'planning' => $session ? [
                        'open' => $session->status === PlanningSession::OPEN, 'estimated' => (int) $session->estimated_count, 'goal' => $session->goal,
                        'attendees' => $this->attendees($session), 'durationSeconds' => $session->durationSeconds(),
                    ] : null,
                ];
            })->values()->all();
    }

    /**
     * Como foi a planning de uma sprint, só para leitura: cada card estimado com as cartas do time, a média, a estimativa
     * da IA, a decisão do PO e o que o time discutiu. Estimativas substituídas por reestimativa ficam de fora.
     *
     * Traz também a carga do time na sprint (como na mesa), para a tela mostrar a mesa daquela sprint só para leitura.
     *
     * Sprint planejada fora do app (sem mesa registrada): a mesma tela, com a carga do time e os cards da sprint.
     *
     * @return array{sprint: int, name: ?string, startedBy: ?string, recorded: bool, closedAt: ?string, open: bool, cards: list<array<string, mixed>>, load: list<array<string, mixed>>, reserveHours: float, rounds: list<array<string, mixed>>}
     */
    public function review(MondayConnection $connection, int $sprint): array
    {
        $sessions = PlanningSession::query()->where('sprint', $sprint)->with('starter')->orderBy('id')->get();
        $snapshot = $this->snapshots->latest($connection, PHP_INT_MAX);
        $schedule = collect($snapshot['sprints'] ?? [])->firstWhere('number', $sprint);
        if ($sessions->isEmpty() && $schedule === null) {
            throw new RuntimeException("A Sprint {$sprint} não está no cronograma de sprints do Monday.");
        }
        $rounds = PlanningRound::query()->whereIn('planning_session_id', $sessions->modelKeys())->where('status', PlanningStatus::Closed)
            ->with(['votes.user', 'decider'])->orderBy('closed_at')->get();
        // Sprint planejada fora do app: sem cartas nem decisões; a tela mostra os cards da sprint com o Tempo estimado do Monday.
        $cards = $sessions->isEmpty() ? collect($snapshot['rows'] ?? [])
            ->filter(fn (array $row): bool => (int) ($row['Sprint'] ?? 0) === $sprint && ! SprintRules::isRemoved($row['Status'] ?? null))
            ->sortByDesc(fn (array $row): float => (float) ($row['EstH'] ?? 0))
            ->map(fn (array $row): array => ['name' => $row['Nome'], 'url' => ($row['Url'] ?? '') ?: null, 'owner' => ($row['Responsavel'] ?? '') ?: null, 'type' => ($row['Tipo'] ?? '') ?: null, 'estimate' => $row['EstH'] ?? null])
            ->values()->all() : [];

        return [
            'sprint' => $sprint, 'name' => $sessions->last()?->sprint_name ?? $schedule['name'], 'startedBy' => $sessions->first()?->starter?->name,
            'recorded' => $sessions->isNotEmpty(),
            'goal' => $sessions->last()?->goal,
            'closedAt' => $sessions->last()?->closed_at?->toIso8601String(),
            'open' => $sessions->last()?->status === PlanningSession::OPEN,
            'attendees' => $sessions->last() ? $this->attendees($sessions->last()->load('participants.user')) : [],
            'durationSeconds' => $sessions->last()?->durationSeconds(),
            'cards' => $cards,
            'load' => $this->load($connection, $sprint, $rounds),
            'reserveHours' => $connection->teamRules()->configured() ? round($connection->supportReserveMinutes($sprint) / 60, 2) : 0.0,
            'rounds' => $rounds->map(fn (PlanningRound $round): array => [
                'name' => $round->item_name, 'url' => $round->item_url, 'owner' => $round->item_owner,
                'aiEstimate' => $round->ai_estimate, 'average' => $round->average, 'finalEstimate' => $round->final_estimate, 'finalSource' => $round->final_source,
                'decidedBy' => $round->decider?->name, 'note' => $round->decision_note, 'closedAt' => $round->closed_at?->toIso8601String(),
                'votes' => $round->votes->map(fn (PlanningVote $vote): array => ['name' => $vote->user?->name ?? 'Sem nome', 'hours' => $vote->hours])
                    ->sortBy('name')->values()->all(),
            ])->all(),
        ];
    }

    /**
     * Abre a mesa de uma sprint que ainda não terminou. Só existe uma mesa aberta por vez. Se a sprint já teve mesa e
     * ainda não terminou, a mesma mesa é reaberta, com as estimativas já feitas.
     */
    public function openTable(MondayConnection $connection, User $user, int $sprint): PlanningSession
    {
        $option = collect($this->sprintOptions($connection))->firstWhere('number', $sprint)
            ?? throw new RuntimeException('Escolha uma sprint atual ou futura do cronograma.');

        return DB::transaction(function () use ($user, $option): PlanningSession {
            $open = PlanningSession::query()->where('status', PlanningSession::OPEN)->lockForUpdate()->first();
            if ($open !== null && $open->sprint === $option['number']) {
                return $open;
            }
            if ($open !== null) {
                throw new RuntimeException("Já existe uma planning aberta: {$open->sprint_name}. Encerre antes de abrir outra.");
            }
            $previous = PlanningSession::query()->where('sprint', $option['number'])->latest('id')->lockForUpdate()->first();
            if ($previous !== null) {
                // Reaberta: o tempo das aberturas anteriores fica somado e a nova abertura começa a contar agora.
                $previous->update(['status' => PlanningSession::OPEN, 'closed_at' => null, 'open_seconds' => $previous->opened_at ? $previous->open_seconds : $previous->durationSeconds(), 'opened_at' => now()]);

                return $previous;
            }

            return PlanningSession::create(['sprint' => $option['number'], 'sprint_name' => $option['name'], 'status' => PlanningSession::OPEN, 'opened_at' => now(), 'started_by' => $user->id]);
        });
    }

    /**
     * Encerra a mesa; um card que ainda estava nela sai sem gravar nada. Antes, cada card estimado na mesa é ligado à
     * sprint da planning pela coluna Sprint do quadro Tarefas (os que já estão nela ficam como estão). Se o Monday recusar
     * algum, a mesa continua aberta para tentar de novo.
     */
    public function closeTable(MondayConnection $connection, PlanningSession $session, string $goal): void
    {
        if ($session->status !== PlanningSession::OPEN) {
            throw new RuntimeException('Esta planning já foi encerrada.');
        }
        if (trim($goal) === '') {
            throw new RuntimeException('Informe o objetivo da sprint para encerrar a planning.');
        }
        $this->linkEstimatedToSprint($connection, $session);
        DB::transaction(function () use ($session, $goal): void {
            $session->rounds()->whereIn('status', PlanningStatus::open())->update(['status' => PlanningStatus::Canceled, 'closed_at' => now()]);
            // O objetivo fica na mesa da sprint (uma só, reaberta quando preciso) e é lido pelo histórico e pelo Daily Hub.
            $session->update(['status' => PlanningSession::CLOSED, 'closed_at' => now(), 'goal' => trim($goal), 'open_seconds' => $session->durationSeconds(), 'opened_at' => null]);
        });
    }

    /** Objetivo registrado para a sprint (null quando a sprint não teve planning no app ou é anterior ao objetivo). */
    public function goal(int $sprint): ?string
    {
        return PlanningSession::query()->where('sprint', $sprint)->whereNotNull('goal')->latest('id')->value('goal');
    }

    /**
     * Liga os cards estimados na mesa à sprint da planning (coluna Sprint, que conecta ao quadro de Sprints).
     *
     * @return int quantos cards foram ligados agora
     */
    private function linkEstimatedToSprint(MondayConnection $connection, PlanningSession $session): int
    {
        $estimated = $session->rounds()->where('status', PlanningStatus::Closed)->get()->unique('item_id');
        $snapshot = $this->snapshots->latest($connection, PHP_INT_MAX);
        $inSprint = collect($snapshot['rows'] ?? [])->where('Sprint', $session->sprint)->pluck('Id')->map(fn (mixed $id): string => (string) $id)->all();
        $pending = $estimated->reject(fn (PlanningRound $round): bool => in_array($round->item_id, $inSprint, true));
        if ($pending->isEmpty()) {
            return 0;
        }
        $column = (string) (($connection->column_map ?? [])['Sprint'] ?? '');
        $sprintItem = array_search($session->sprint, $snapshot['sprintItems'] ?? [], true);
        if ($column === '' || $sprintItem === false) {
            throw new RuntimeException("Não encontrei a {$session->sprint_name} no quadro de Sprints do Monday para ligar os cards estimados. Atualize os dados e tente encerrar de novo.");
        }
        $failed = [];
        $linked = 0;
        foreach ($pending as $round) {
            try {
                $this->monday->changeItemColumn((string) $connection->token, [(string) $connection->board_id => null], $round->item_id, $column, [(string) $sprintItem]);
                $linked++;
            } catch (RuntimeException $exception) {
                report($exception);
                $failed[] = $round->item_name;
            }
        }
        if ($linked > 0) {
            $this->snapshots->markStale();
        }
        if ($failed !== []) {
            throw new RuntimeException("O Monday não ligou à {$session->sprint_name}: ".implode(', ', $failed).'. A planning continua aberta; tente encerrar de novo.');
        }
        Log::info('Cards estimados ligados à sprint ao encerrar a planning.', ['sprint' => $session->sprint, 'cards' => $linked]);

        return $linked;
    }

    /**
     * Joga o card no centro da mesa para leitura. Lê agora no Monday a estimativa da IA (Tempo estimado) e o escopo mais
     * recente publicado no card.
     *
     * Reestimar: um card já estimado nesta planning volta para a mesa mesmo que não esteja mais no Backlog. A estimativa
     * da IA é a original da primeira rodada, porque o Tempo estimado do card já tem o valor gravado pela planning.
     *
     * Interromper: com $replace, o card que estava na mesa sai sem gravar nada (cartas descartadas) e volta para a mão,
     * na mesma transação em que o novo card entra.
     */
    public function playCard(MondayConnection $connection, PlanningSession $session, User $user, string $itemId, bool $replace = false): PlanningRound
    {
        if ($session->status !== PlanningSession::OPEN) {
            throw new RuntimeException('Esta planning já foi encerrada.');
        }
        $previous = $session->rounds()->where('item_id', $itemId)->where('status', PlanningStatus::Closed)->latest('closed_at')->first();
        $card = collect($this->backlog($connection)['items'])->firstWhere('Id', $itemId)
            ?? ($previous ? ['Id' => $previous->item_id, 'Nome' => $previous->item_name, 'Url' => $previous->item_url, 'Responsavel' => $previous->item_owner] : null)
            ?? throw new RuntimeException('Este card não está no Backlog do quadro Tarefas (sem sprint e não iniciado).');
        $details = $this->cardDetails($connection, $itemId);
        if ($previous !== null) {
            $details['aiEstimate'] = $previous->ai_estimate;
        }

        return DB::transaction(function () use ($session, $user, $card, $details, $replace): PlanningRound {
            $open = PlanningRound::query()->where('planning_session_id', $session->id)->whereIn('status', PlanningStatus::open())->lockForUpdate()->first();
            if ($open !== null && ! $replace) {
                throw new RuntimeException("O card {$open->item_name} ainda está na mesa. Registre a estimativa ou tire o card antes de jogar outro.");
            }
            $open?->update(['status' => PlanningStatus::Canceled, 'closed_at' => now()]);

            return PlanningRound::create([
                'planning_session_id' => $session->id,
                'item_id' => $card['Id'], 'item_name' => $card['Nome'], 'item_url' => ($card['Url'] ?? '') ?: null, 'item_owner' => ($card['Responsavel'] ?? '') ?: null,
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

        DB::transaction(function () use ($round, $estimate, $source, $user, $note): void {
            // Reestimativa: a estimativa anterior do mesmo card nesta planning é substituída e sai da lista e da carga.
            $round->session->rounds()->where('item_id', $round->item_id)->where('status', PlanningStatus::Closed)->whereKeyNot($round->id)
                ->update(['status' => PlanningStatus::Canceled]);
            $round->update([
                'status' => PlanningStatus::Closed, 'final_estimate' => $estimate, 'final_source' => $source, 'decided_by' => $user->id,
                'decision_note' => filled($note) ? trim($note) : null, 'closed_at' => now(),
            ]);
        });
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

    /**
     * Quem esteve na mesa da sprint (em qualquer abertura), em ordem alfabética.
     *
     * @return list<string>
     */
    private function attendees(PlanningSession $session): array
    {
        return $session->participants->map(fn (PlanningParticipant $participant): ?string => $participant->user?->name)->filter()->sort()->values()->all();
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
                'id' => $seat->id, 'name' => $seat->name, 'isMe' => $seat->id === $user->id, 'online' => $present->contains($seat->id),
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
