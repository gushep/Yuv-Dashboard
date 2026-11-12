<?php

namespace App\Services;

use App\Models\DailyAction;
use App\Models\MondayConnection;
use App\Models\Retrospective;
use App\Models\RetrospectiveAction;
use App\Models\RetrospectiveActionEvent;
use App\Models\RetrospectiveCard;
use App\Models\RetrospectiveParticipant;
use App\Models\RetrospectiveTopic;
use App\Models\RetrospectiveVote;
use App\Models\User;
use App\Support\SprintRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sprint Retrospective. O facilitador (Maestro Scrum) abre a retro de uma sprint já fechada no Monday e conduz o time
 * por sete etapas: abertura, revisão das ações anteriores, dados da sprint, visão do time (cartas viradas até a
 * revelação, depois votos), discussão, foco e plano de ação. Ao encerrar, os indicadores ficam congelados e as ações
 * seguem em acompanhamento (na tela da retro e na Daily) até o responsável ou o facilitador concluir ou descartar.
 */
class RetrospectiveService
{
    /** Quem consultou a sala nos últimos segundos aparece como presente. */
    public const PRESENCE_SECONDS = 20;

    /** Sprints mostradas no histórico da tela. */
    public const HISTORY_SPRINTS = 8;

    /** Votos de cada pessoa nas cartas reveladas. */
    public const VOTES_PER_PERSON = 3;

    /** Etapas da retro e o tempo previsto de cada uma, em minutos (45 minutos no total). */
    public const STEPS = [
        ['name' => 'Abertura', 'minutes' => 3],
        ['name' => 'Ações anteriores', 'minutes' => 5],
        ['name' => 'Dados da sprint', 'minutes' => 7],
        ['name' => 'Visão do time', 'minutes' => 12],
        ['name' => 'Discussão', 'minutes' => 10],
        ['name' => 'Foco', 'minutes' => 3],
        ['name' => 'Plano de ação', 'minutes' => 5],
    ];

    /** Etapa da discussão: ao chegar nela, as cartas votadas viram temas. */
    private const DISCUSSION_STEP = 4;

    public const DEFAULT_GOAL = 'Aprender com a sprint e escolher até duas melhorias concretas para a próxima.';

    public const DEFAULT_RULES = [
        ['title' => '🎯 Foco no processo', 'text' => 'Investigar o sistema e as dependências, sem procurar culpados.'],
        ['title' => '🔍 Exemplos concretos', 'text' => 'Falar de fatos e situações reais, não de suposições.'],
        ['title' => '👂 Espaço para todos', 'text' => 'Escuta ativa: garantir que todas as vozes sejam ouvidas.'],
        ['title' => '↔️ Divergir para convergir', 'text' => 'Registrar divergências sem forçar consenso imediato.'],
    ];

    public function __construct(private MondaySnapshotService $snapshots, private PlanningService $planning, private SprintReviewService $reviews) {}

    // ── Tela inicial ─────────────────────────────────────────────────────────────

    /**
     * Tela inicial: cards das ações de todas as retros, a lista de ações, a retro em andamento e as últimas sprints com
     * o resumo de cada retro.
     *
     * @return array<string, mixed>
     */
    public function overview(MondayConnection $connection, User $user): array
    {
        $today = SprintRules::today();
        $sprints = $this->sprints($connection)->filter(fn (array $sprint): bool => $sprint['start'] <= $today)->take(self::HISTORY_SPRINTS)->values();
        $retros = Retrospective::query()->whereIn('sprint', $sprints->pluck('number'))->with(['facilitator', 'cards', 'topics', 'actions', 'participants.user'])->get()->keyBy('sprint');
        $actions = RetrospectiveAction::query()->with(['retrospective.facilitator', 'responsible', 'closer', 'events.user'])->get();
        $open = $this->openRetro();
        $presented = $actions->map(fn (RetrospectiveAction $action): array => $this->presentAction($action, $user, $today, $open))
            ->sortBy(fn (array $action): string => ($action['status'] === RetrospectiveAction::OPEN ? '0' : '1').($action['dueDate'] ?? '9999'))->values();

        return [
            'canFacilitate' => $user->canFacilitateRetrospectives(),
            'today' => $today,
            'kpis' => $this->actionKpis($actions, $today),
            'actions' => $presented->all(),
            'open' => $open ? [
                'sprint' => $open->sprint, 'sprintName' => $open->sprint_name, 'facilitator' => $open->facilitator?->name,
                'present' => $this->presentParticipants($open)->map(fn (RetrospectiveParticipant $participant): ?string => $participant->user?->name)->filter()->values()->all(),
                'elapsedSeconds' => array_sum($this->stepSeconds($open)),
                'step' => $open->step, 'stepName' => self::STEPS[$open->step]['name'],
            ] : null,
            'sprints' => $sprints->map(fn (array $sprint): array => [
                ...$sprint, 'current' => $sprint['start'] <= $today && $sprint['end'] >= $today,
                'retro' => isset($retros[$sprint['number']]) ? $this->summary($retros[$sprint['number']], $today) : null,
            ])->all(),
        ];
    }

    /**
     * Ações abertas de todas as retros, para o card da Daily: aparecem em todas as Dailies até serem concluídas ou
     * descartadas.
     *
     * @return list<array<string, mixed>>
     */
    public function openActions(User $user): array
    {
        $today = SprintRules::today();

        return RetrospectiveAction::query()->where('status', RetrospectiveAction::OPEN)
            ->with(['retrospective.facilitator', 'responsible', 'closer', 'events.user'])->get()
            ->map(fn (RetrospectiveAction $action): array => $this->presentAction($action, $user, $today, null))
            ->sortBy(fn (array $action): string => $action['dueDate'] ?? '9999')->values()->all();
    }

    // ── Sala ─────────────────────────────────────────────────────────────────────

    /**
     * A retro em andamento como cada pessoa vê. Consultar a sala também coloca a pessoa nela.
     *
     * @return array<string, mixed>
     */
    public function room(User $user): array
    {
        $retro = $this->openRetro();
        if ($retro === null) {
            return ['retro' => null, 'canFacilitate' => $user->canFacilitateRetrospectives()];
        }
        RetrospectiveParticipant::updateOrCreate(['retrospective_id' => $retro->id, 'user_id' => $user->id], ['last_seen_at' => now()]);

        return ['retro' => $this->present($retro, $user), 'canFacilitate' => $user->canFacilitateRetrospectives()];
    }

    /**
     * Modo leitura (olhinho) da retro de uma sprint. Sem retro no app, vem só a sprint, para a tela mostrar os dados.
     *
     * @return array<string, mixed>
     */
    public function review(MondayConnection $connection, User $user, int $sprint): array
    {
        $info = $this->sprints($connection)->firstWhere('number', $sprint)
            ?? throw new RuntimeException("Sprint {$sprint} não encontrada no cronograma de sprints do Monday.");
        $retro = Retrospective::query()->where('sprint', $sprint)->first();

        return ['sprint' => $info, 'sprintGoal' => $this->planning->goal($sprint), 'dailyStats' => $this->dailyStats($sprint), 'review' => $this->reviews->summaryFor($sprint), 'retro' => $retro ? $this->present($retro, $user) : null];
    }

    /**
     * Abre a retro de uma sprint fechada no Monday (caixa "Concluído?" do cronograma de sprints). Só uma retro por
     * sprint e só uma em andamento por vez; quem abre é o facilitador.
     */
    public function start(MondayConnection $connection, User $user, int $sprint): Retrospective
    {
        if (! $user->canFacilitateRetrospectives()) {
            throw new RuntimeException('Só o Maestro Scrum pode iniciar a retrospectiva.');
        }
        $info = $this->sprints($connection)->firstWhere('number', $sprint)
            ?? throw new RuntimeException("Sprint {$sprint} não encontrada no cronograma de sprints do Monday.");
        if (! ($info['completed'] ?? false)) {
            throw new RuntimeException("A {$info['name']} ainda não foi fechada no Monday (caixa \"Concluído?\" do cronograma de sprints). Se ela já foi fechada, atualize os dados.");
        }

        return DB::transaction(function () use ($user, $info): Retrospective {
            $open = Retrospective::query()->where('status', Retrospective::OPEN)->lockForUpdate()->first();
            if ($open !== null) {
                throw new RuntimeException("Encerre a retro da {$open->sprint_name} antes de iniciar outra.");
            }
            if (Retrospective::query()->where('sprint', $info['number'])->exists()) {
                throw new RuntimeException("A retro da {$info['name']} já foi feita. Veja pelo olhinho.");
            }

            return Retrospective::query()->create([
                'sprint' => $info['number'], 'sprint_name' => $info['name'], 'status' => Retrospective::OPEN, 'facilitator_id' => $user->id,
                'step' => 0, 'step_started_at' => now(), 'step_seconds' => [], 'goal' => self::DEFAULT_GOAL, 'rules' => self::DEFAULT_RULES, 'started_at' => now(),
            ]);
        });
    }

    /** Avança ou volta de etapa; o tempo gasto na etapa anterior fica guardado para o cronômetro. */
    public function step(User $user, Retrospective $retro, int $step): void
    {
        $this->conducting($user, $retro, function (Retrospective $locked) use ($step): void {
            if ($step < 0 || $step >= count(self::STEPS)) {
                throw new RuntimeException('Etapa inválida.');
            }
            $locked->update(['step_seconds' => $this->stepSeconds($locked), 'step' => $step, 'step_started_at' => now()]);
            if ($step === self::DISCUSSION_STEP) {
                $this->syncTopics($locked);
            }
        });
    }

    /**
     * Textos que o facilitador edita: objetivo da retro, combinados, contexto observado e dependências.
     *
     * @param  array{goal?: string, rules?: list<array{title: string, text: string}>, context?: ?string, dependencies?: ?string}  $fields
     */
    public function setup(User $user, Retrospective $retro, array $fields): void
    {
        $this->conducting($user, $retro, fn (Retrospective $locked) => $locked->update($fields));
    }

    /** Vira as cartas: a partir daqui todos veem o texto e quem escreveu, e a votação começa. */
    public function reveal(User $user, Retrospective $retro): void
    {
        $this->conducting($user, $retro, function (Retrospective $locked): void {
            if ($locked->revealed_at !== null) {
                throw new RuntimeException('As cartas já foram reveladas.');
            }
            $locked->update(['revealed_at' => now()]);
        });
    }

    public function addCard(User $user, Retrospective $retro, string $column, string $text): void
    {
        $this->ensureOpen($retro);
        $retro->cards()->create(['user_id' => $user->id, 'column' => $column, 'text' => trim($text)]);
    }

    /** Apaga uma carta: o autor, enquanto ela está virada, ou o facilitador. */
    public function deleteCard(User $user, RetrospectiveCard $card): void
    {
        $retro = Retrospective::query()->findOrFail($card->retrospective_id);
        $this->ensureOpen($retro);
        $isAuthor = $card->user_id === $user->id && $retro->revealed_at === null;
        if (! $isAuthor && ! $this->facilitates($user, $retro)) {
            throw new RuntimeException('Só quem escreveu (antes da revelação) ou o facilitador pode apagar a carta.');
        }
        $card->delete();
    }

    /** Liga ou desliga o voto da pessoa na carta; cada pessoa tem VOTES_PER_PERSON votos por retro. */
    public function vote(User $user, RetrospectiveCard $card): void
    {
        DB::transaction(function () use ($user, $card): void {
            $retro = Retrospective::query()->lockForUpdate()->findOrFail($card->retrospective_id);
            $this->ensureOpen($retro);
            if ($retro->revealed_at === null) {
                throw new RuntimeException('A votação começa depois que o facilitador revelar as cartas.');
            }
            $existing = RetrospectiveVote::query()->where('retrospective_card_id', $card->id)->where('user_id', $user->id)->first();
            if ($existing !== null) {
                $existing->delete();

                return;
            }
            if ($this->votesUsed($retro, $user) >= self::VOTES_PER_PERSON) {
                throw new RuntimeException('Você já usou os '.self::VOTES_PER_PERSON.' votos. Tire um voto para votar em outra carta.');
            }
            RetrospectiveVote::query()->create(['retrospective_card_id' => $card->id, 'user_id' => $user->id]);
        });
    }

    /** Agrupa uma carta dentro de outra parecida da mesma retro (os votos das duas somam). */
    public function merge(User $user, RetrospectiveCard $card, RetrospectiveCard $target): void
    {
        if ($card->retrospective_id !== $target->retrospective_id || $card->is($target) || $target->merged_into_id !== null) {
            throw new RuntimeException('Escolha outra carta desta retro para agrupar.');
        }
        $this->conducting($user, Retrospective::query()->findOrFail($card->retrospective_id), function () use ($card, $target): void {
            RetrospectiveCard::query()->where('merged_into_id', $card->id)->update(['merged_into_id' => $target->id]);
            $card->update(['merged_into_id' => $target->id]);
        });
    }

    /** Voto da pessoa sobre o objetivo da sprint: atingido, parcial ou não atingido. */
    public function goalVote(User $user, Retrospective $retro, string $vote): void
    {
        DB::transaction(function () use ($user, $retro, $vote): void {
            $locked = Retrospective::query()->lockForUpdate()->findOrFail($retro->id);
            $this->ensureOpen($locked);
            $locked->update(['goal_votes' => [...($locked->goal_votes ?? []), (string) $user->id => $vote]]);
        });
    }

    public function addTopic(User $user, Retrospective $retro, string $title, ?string $question): void
    {
        $this->conducting($user, $retro, fn (Retrospective $locked) => $locked->topics()->create(['title' => trim($title), 'question' => $question]));
    }

    /**
     * Anotações do tema e escolha de foco (1 ou 2; até dois focos por retro, um tema por foco).
     *
     * @param  array{notes?: ?string, focus?: ?int}  $fields
     */
    public function updateTopic(User $user, RetrospectiveTopic $topic, array $fields): void
    {
        $this->conducting($user, Retrospective::query()->findOrFail($topic->retrospective_id), function (Retrospective $locked) use ($topic, $fields): void {
            if (array_key_exists('focus', $fields) && $fields['focus'] !== null) {
                $locked->topics()->where('focus', $fields['focus'])->whereKeyNot($topic->id)->update(['focus' => null]);
            }
            $topic->update($fields);
        });
    }

    /**
     * Ação do plano. O card é opcional e precisa estar na base sincronizada.
     *
     * @param  array{description: string, responsible_id?: ?int, due_date?: ?string, signal?: ?string, focus?: ?int, item_id?: ?string}  $data
     */
    public function addAction(MondayConnection $connection, User $user, Retrospective $retro, array $data): void
    {
        $item = [];
        if (! empty($data['item_id'])) {
            $row = collect($this->snapshots->latest($connection, PHP_INT_MAX)['rows'] ?? [])->first(fn (array $row): bool => (string) ($row['Id'] ?? '') === $data['item_id'])
                ?? throw new RuntimeException('Card não encontrado nos dados sincronizados do Monday. Atualize os dados e tente de novo.');
            $item = ['item_id' => $data['item_id'], 'item_name' => (string) ($row['Nome'] ?? ''), 'item_url' => ($row['Url'] ?? '') ?: null];
        }
        $this->conducting($user, $retro, function (Retrospective $locked) use ($user, $data, $item): void {
            $action = $locked->actions()->create([
                'description' => trim($data['description']), 'responsible_id' => $data['responsible_id'] ?? null, 'due_date' => $data['due_date'] ?? null,
                'signal' => $data['signal'] ?? null, 'focus' => $data['focus'] ?? null, ...$item, 'status' => RetrospectiveAction::OPEN,
            ]);
            $action->events()->create(['type' => RetrospectiveActionEvent::CREATED, 'user_id' => $user->id, 'retrospective_id' => $locked->id]);
        });
    }

    /** Tira uma ação do plano enquanto a retro dela está em andamento. */
    public function deleteAction(User $user, RetrospectiveAction $action): void
    {
        $this->conducting($user, Retrospective::query()->findOrFail($action->retrospective_id), fn () => $action->delete());
    }

    /**
     * Encerra a retro: congela os indicadores da sprint (calculados pela tela com as mesmas regras do Dashboard) e manda
     * as ações para o acompanhamento.
     *
     * @param  array<string, mixed>  $metrics
     */
    public function close(User $user, Retrospective $retro, array $metrics): void
    {
        $this->conducting($user, $retro, fn (Retrospective $locked) => $locked->update([
            'step_seconds' => $this->stepSeconds($locked), 'step_started_at' => null,
            'status' => Retrospective::CLOSED, 'closed_at' => now(), 'metrics' => $metrics,
        ]));
    }

    // ── Acompanhamento das ações ─────────────────────────────────────────────────

    /**
     * Quem pode concluir, descartar, reabrir ou manter uma ação: o responsável e o facilitador da retro que a criou. Na
     * revisão das ações anteriores, o facilitador da retro em andamento também registra a decisão do time.
     */
    public function canManage(User $user, RetrospectiveAction $action, ?Retrospective $open = null): bool
    {
        return $action->responsible_id === $user->id
            || $action->retrospective?->facilitator_id === $user->id
            || ($open !== null && $open->facilitator_id === $user->id && $open->id !== $action->retrospective_id);
    }

    public function complete(User $user, RetrospectiveAction $action, string $result, ?bool $signalReached): void
    {
        $this->managing($user, $action, true, function (RetrospectiveAction $locked, ?Retrospective $open) use ($user, $result, $signalReached): void {
            $locked->update(['status' => RetrospectiveAction::DONE, 'result' => trim($result), 'signal_reached' => $signalReached, 'closed_at' => now(), 'closed_by' => $user->id]);
            $locked->events()->create(['type' => RetrospectiveActionEvent::DONE, 'user_id' => $user->id, 'note' => trim($result), 'retrospective_id' => $open?->id]);
        });
    }

    public function drop(User $user, RetrospectiveAction $action, string $reason): void
    {
        $this->managing($user, $action, true, function (RetrospectiveAction $locked, ?Retrospective $open) use ($user, $reason): void {
            $locked->update(['status' => RetrospectiveAction::DROPPED, 'result' => trim($reason), 'signal_reached' => null, 'closed_at' => now(), 'closed_by' => $user->id]);
            $locked->events()->create(['type' => RetrospectiveActionEvent::DROPPED, 'user_id' => $user->id, 'note' => trim($reason), 'retrospective_id' => $open?->id]);
        });
    }

    /** Mantém a ação em andamento (revisão na retro seguinte), com prazo renovado se informado. */
    public function keep(User $user, RetrospectiveAction $action, ?string $dueDate, ?string $note): void
    {
        $this->managing($user, $action, true, function (RetrospectiveAction $locked, ?Retrospective $open) use ($user, $dueDate, $note): void {
            if ($dueDate !== null) {
                $locked->update(['due_date' => $dueDate]);
            }
            $locked->events()->create(['type' => RetrospectiveActionEvent::CONTINUED, 'user_id' => $user->id, 'note' => $note, 'due_date' => $dueDate, 'retrospective_id' => $open?->id]);
        });
    }

    public function reopen(User $user, RetrospectiveAction $action): void
    {
        $this->managing($user, $action, false, function (RetrospectiveAction $locked, ?Retrospective $open) use ($user): void {
            $locked->update(['status' => RetrospectiveAction::OPEN, 'result' => null, 'signal_reached' => null, 'closed_at' => null, 'closed_by' => null]);
            $locked->events()->create(['type' => RetrospectiveActionEvent::REOPENED, 'user_id' => $user->id, 'retrospective_id' => $open?->id]);
        });
    }

    // ── Apoio ────────────────────────────────────────────────────────────────────

    /**
     * Sprints do cronograma do Monday, da mais recente para a mais antiga.
     *
     * @return Collection<int, array{number: int, name: string, start: string, end: string, completed: bool}>
     */
    private function sprints(MondayConnection $connection): Collection
    {
        return collect($this->snapshots->latest($connection, PHP_INT_MAX)['sprints'] ?? [])
            ->map(fn (array $sprint): array => ['number' => (int) $sprint['number'], 'name' => (string) $sprint['name'], 'start' => (string) $sprint['start'], 'end' => (string) $sprint['end'], 'completed' => (bool) ($sprint['completed'] ?? false)])
            ->sortByDesc('number')->values();
    }

    private function openRetro(): ?Retrospective
    {
        return Retrospective::query()->where('status', Retrospective::OPEN)->with('facilitator')->first();
    }

    /** Facilitador da retro: quem abriu ou outro Maestro Scrum (para a sala não travar se ele sair). */
    private function facilitates(User $user, Retrospective $retro): bool
    {
        return $retro->facilitator_id === $user->id || $user->canFacilitateRetrospectives();
    }

    private function ensureOpen(Retrospective $retro): void
    {
        if (! $retro->isOpen()) {
            throw new RuntimeException('Esta retro já foi encerrada.');
        }
    }

    /** Passo do facilitador com a retro travada: só ele conduz e só enquanto a retro está em andamento. */
    private function conducting(User $user, Retrospective $retro, callable $callback): void
    {
        DB::transaction(function () use ($user, $retro, $callback): void {
            $locked = Retrospective::query()->lockForUpdate()->findOrFail($retro->id);
            $this->ensureOpen($locked);
            if (! $this->facilitates($user, $locked)) {
                throw new RuntimeException('Só o facilitador conduz a retro.');
            }
            $callback($locked);
        });
    }

    private function managing(User $user, RetrospectiveAction $action, bool $mustBeOpen, callable $callback): void
    {
        DB::transaction(function () use ($user, $action, $mustBeOpen, $callback): void {
            $locked = RetrospectiveAction::query()->with('retrospective')->lockForUpdate()->findOrFail($action->id);
            $open = $this->openRetro();
            if (! $this->canManage($user, $locked, $open)) {
                throw new RuntimeException('Só o responsável pela ação e o facilitador da retro podem alterar a ação.');
            }
            if ($mustBeOpen !== $locked->isOpen()) {
                throw new RuntimeException($mustBeOpen ? 'Esta ação já foi encerrada.' : 'Esta ação já está em andamento.');
            }
            $callback($locked, $open);
        });
    }

    /** Ao chegar na discussão, cada carta votada (que ainda não é tema) vira um tema. */
    private function syncTopics(Retrospective $retro): void
    {
        $existing = $retro->topics()->whereNotNull('retrospective_card_id')->pluck('retrospective_card_id')->all();
        foreach ($this->cardGroups($retro) as $group) {
            if ($group['votes'] > 0 && ! in_array($group['card']->id, $existing, true)) {
                $retro->topics()->create(['retrospective_card_id' => $group['card']->id, 'title' => mb_substr($group['card']->text, 0, 500)]);
            }
        }
    }

    /**
     * Cartas principais com as agrupadas dentro e a soma dos votos do grupo.
     *
     * @return Collection<int, array{card: RetrospectiveCard, merged: Collection<int, RetrospectiveCard>, votes: int, voters: list<int>}>
     */
    private function cardGroups(Retrospective $retro): Collection
    {
        $cards = $retro->cards()->with(['author', 'votes'])->get();

        return $cards->whereNull('merged_into_id')->map(function (RetrospectiveCard $card) use ($cards): array {
            $merged = $cards->where('merged_into_id', $card->id)->values();
            $voters = $card->votes->pluck('user_id')->merge($merged->flatMap(fn (RetrospectiveCard $item) => $item->votes->pluck('user_id')))->all();

            return ['card' => $card, 'merged' => $merged, 'votes' => count($voters), 'voters' => $voters];
        })->values();
    }

    private function votesUsed(Retrospective $retro, User $user): int
    {
        return RetrospectiveVote::query()->where('user_id', $user->id)
            ->whereIn('retrospective_card_id', RetrospectiveCard::query()->where('retrospective_id', $retro->id)->select('id'))->count();
    }

    /**
     * Segundos gastos em cada etapa, somando o trecho da etapa atual.
     *
     * @return array<string, int>
     */
    private function stepSeconds(Retrospective $retro): array
    {
        $seconds = $retro->step_seconds ?? [];
        if ($retro->step_started_at !== null) {
            $seconds[(string) $retro->step] = ($seconds[(string) $retro->step] ?? 0) + (int) $retro->step_started_at->diffInSeconds(now(), true);
        }

        return $seconds;
    }

    /**
     * Pessoas presentes na sala agora.
     *
     * @return Collection<int, RetrospectiveParticipant>
     */
    private function presentParticipants(Retrospective $retro): Collection
    {
        return $retro->participants()->with('user')->where('last_seen_at', '>=', now()->subSeconds(self::PRESENCE_SECONDS))->get();
    }

    /**
     * Responsabilidades da Daily da sprint: quantas foram abertas, quantas resolvidas e o tempo médio de resolução.
     *
     * @return array{total: int, done: int, averageMinutes: ?int}
     */
    private function dailyStats(int $sprint): array
    {
        $actions = DailyAction::query()->where('sprint', $sprint)->get(['status', 'opened_at', 'closed_at']);
        $done = $actions->where('status', DailyAction::DONE)->filter(fn (DailyAction $action): bool => $action->closed_at !== null);

        return [
            'total' => $actions->count(),
            'done' => $done->count(),
            'averageMinutes' => $done->isEmpty() ? null : (int) round($done->avg(fn (DailyAction $action): float => $action->opened_at->diffInMinutes($action->closed_at, true))),
        ];
    }

    /**
     * @param  Collection<int, RetrospectiveAction>  $actions
     * @return array{open: int, late: int, done: int, signalReached: int, dropped: int, total: int, rate: ?int}
     */
    private function actionKpis(Collection $actions, string $today): array
    {
        $done = $actions->where('status', RetrospectiveAction::DONE);
        $dropped = $actions->where('status', RetrospectiveAction::DROPPED)->count();
        $late = $actions->filter(fn (RetrospectiveAction $action): bool => $action->isLate($today))->count();
        $considered = $actions->count() - $dropped;

        return [
            'open' => $actions->where('status', RetrospectiveAction::OPEN)->count() - $late, 'late' => $late, 'done' => $done->count(),
            'signalReached' => $done->where('signal_reached', true)->count(), 'dropped' => $dropped, 'total' => $actions->count(),
            'rate' => $considered > 0 ? (int) round($done->count() / $considered * 100) : null,
        ];
    }

    /**
     * Resumo de uma retro para a linha da sprint na tela inicial.
     *
     * @return array<string, mixed>
     */
    private function summary(Retrospective $retro, string $today): array
    {
        $cards = $retro->cards->whereNull('merged_into_id');

        return [
            'id' => $retro->id, 'status' => $retro->status, 'date' => $retro->started_at->toDateString(), 'facilitator' => $retro->facilitator?->name,
            'step' => $retro->step, 'stepName' => self::STEPS[$retro->step]['name'],
            'cards' => ['keep' => $cards->where('column', 'keep')->count(), 'improve' => $cards->where('column', 'improve')->count(), 'try' => $cards->where('column', 'try')->count()],
            'focus' => $retro->topics->whereNotNull('focus')->count(),
            'actions' => $this->actionKpis($retro->actions, $today),
            'attendees' => $retro->participants->map(fn (RetrospectiveParticipant $participant): ?string => $participant->user?->name)->filter()->sort()->values()->all(),
            'durationSeconds' => array_sum($this->stepSeconds($retro)),
        ];
    }

    /**
     * A retro completa como a pessoa vê: cartas viradas para os outros até a revelação.
     *
     * @return array<string, mixed>
     */
    private function present(Retrospective $retro, User $user): array
    {
        $retro->loadMissing(['facilitator', 'topics.card', 'actions.responsible', 'actions.closer', 'actions.events.user', 'actions.retrospective']);
        $today = SprintRules::today();
        $revealed = $retro->revealed_at !== null;
        $groups = $this->cardGroups($retro);
        $card = fn (RetrospectiveCard $card): array => [
            'id' => $card->id, 'column' => $card->column, 'author' => $card->author?->name, 'mine' => $card->user_id === $user->id,
            'hidden' => ! $revealed && $card->user_id !== $user->id, 'text' => $revealed || $card->user_id === $user->id ? $card->text : null,
        ];
        $votesByCard = $groups->mapWithKeys(fn (array $group): array => [$group['card']->id => $group['votes']]);
        $open = $retro->isOpen() ? $retro : null;
        $previous = RetrospectiveAction::query()->with(['retrospective.facilitator', 'responsible', 'closer', 'events.user'])
            ->whereHas('retrospective', fn ($query) => $query->where('sprint', '<', $retro->sprint))
            ->where(fn ($query) => $query->when($retro->isOpen(), fn ($inner) => $inner->where('status', RetrospectiveAction::OPEN))
                ->orWhereHas('events', fn ($events) => $events->where('retrospective_id', $retro->id)))
            ->get();
        $goalVotes = collect($retro->goal_votes ?? []);
        $stepSeconds = $this->stepSeconds($retro);

        return [
            'id' => $retro->id, 'sprint' => $retro->sprint, 'sprintName' => $retro->sprint_name, 'status' => $retro->status,
            'facilitator' => $retro->facilitator ? ['id' => $retro->facilitator->id, 'name' => $retro->facilitator->name] : null,
            'canConduct' => $retro->isOpen() && $this->facilitates($user, $retro),
            'me' => ['id' => $user->id, 'name' => $user->name],
            'step' => $retro->step,
            'steps' => collect(self::STEPS)->map(fn (array $step, int $index): array => [...$step, 'seconds' => $stepSeconds[(string) $index] ?? 0])->all(),
            'elapsedSeconds' => array_sum($stepSeconds),
            'startedAt' => $retro->started_at->toIso8601String(), 'closedAt' => $retro->closed_at?->toIso8601String(),
            'goal' => $retro->goal, 'rules' => $retro->rules, 'context' => $retro->context, 'dependencies' => $retro->dependencies,
            'sprintGoal' => $this->planning->goal($retro->sprint),
            'goalVotes' => ['yes' => $goalVotes->filter(fn ($vote) => $vote === 'yes')->count(), 'partial' => $goalVotes->filter(fn ($vote) => $vote === 'partial')->count(),
                'no' => $goalVotes->filter(fn ($vote) => $vote === 'no')->count(), 'mine' => $goalVotes->get((string) $user->id)],
            'revealed' => $revealed,
            'votesPerPerson' => self::VOTES_PER_PERSON,
            'votesLeft' => self::VOTES_PER_PERSON - $this->votesUsed($retro, $user),
            'cards' => $groups->map(fn (array $group): array => [
                ...$card($group['card']), 'votes' => $revealed ? $group['votes'] : 0, 'myVote' => in_array($user->id, $group['voters'], true),
                'merged' => $group['merged']->map($card)->all(),
            ])->all(),
            'topics' => $retro->topics->map(fn (RetrospectiveTopic $topic): array => [
                'id' => $topic->id, 'title' => $topic->title, 'question' => $topic->question, 'notes' => $topic->notes, 'focus' => $topic->focus,
                'column' => $topic->card?->column, 'votes' => $topic->retrospective_card_id ? ($votesByCard[$topic->retrospective_card_id] ?? 0) : null,
            ])->sortByDesc(fn (array $topic): int => $topic['votes'] ?? -1)->values()->all(),
            'actions' => $retro->actions->map(fn (RetrospectiveAction $action): array => $this->presentAction($action, $user, $today, $open))->all(),
            'previousActions' => $previous->map(fn (RetrospectiveAction $action): array => [
                ...$this->presentAction($action, $user, $today, $open),
                'review' => ($event = $action->events->where('retrospective_id', $retro->id)->last()) ? ['type' => $event->type, 'note' => $event->note, 'user' => $event->user?->name] : null,
            ])->all(),
            'metrics' => $retro->metrics,
            'dailyStats' => $this->dailyStats($retro->sprint),
            // Resultado da Sprint Review da mesma sprint (aceite das entregas e objetivo), para a etapa Dados.
            'review' => $this->reviews->summaryFor($retro->sprint),
            'participants' => $retro->isOpen() ? $this->presentParticipants($retro)->map(fn (RetrospectiveParticipant $participant): array => ['id' => $participant->user_id, 'name' => $participant->user?->name])->values()->all() : [],
            'attendees' => $retro->participants()->with('user')->get()->map(fn (RetrospectiveParticipant $participant): ?string => $participant->user?->name)->filter()->sort()->values()->all(),
            'users' => $retro->isOpen() ? User::query()->orderBy('name')->get(['id', 'name'])->map(fn (User $person): array => ['id' => $person->id, 'name' => $person->name])->all() : [],
            'serverNow' => now()->toIso8601String(),
            'stepStartedAt' => $retro->step_started_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAction(RetrospectiveAction $action, User $user, string $today, ?Retrospective $open): array
    {
        $at = fn (?Carbon $value): ?string => $value?->toIso8601String();
        $due = $action->due_date?->toDateString();

        return [
            'id' => $action->id, 'description' => $action->description,
            'retro' => ['id' => $action->retrospective_id, 'sprint' => $action->retrospective?->sprint, 'sprintName' => $action->retrospective?->sprint_name, 'facilitator' => $action->retrospective?->facilitator?->name],
            'responsible' => $action->responsible ? ['id' => $action->responsible->id, 'name' => $action->responsible->name] : null,
            'dueDate' => $due, 'signal' => $action->signal, 'focus' => $action->focus,
            'item' => $action->item_id ? ['id' => $action->item_id, 'name' => $action->item_name, 'url' => $action->item_url] : null,
            'status' => $action->status, 'late' => $action->isLate($today),
            'lateDays' => $action->isLate($today) ? (int) Carbon::parse($due)->diffInDays(Carbon::parse($today), true) : 0,
            'result' => $action->result, 'signalReached' => $action->signal_reached,
            'closedAt' => $at($action->closed_at), 'closedBy' => $action->closer?->name,
            'canManage' => $this->canManage($user, $action, $open),
            'events' => $action->events->map(fn (RetrospectiveActionEvent $event): array => [
                'type' => $event->type, 'at' => $at($event->created_at), 'user' => $event->user?->name, 'note' => $event->note, 'dueDate' => $event->due_date?->toDateString(),
            ])->all(),
        ];
    }
}
