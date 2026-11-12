<?php

namespace App\Services;

use App\Models\MondayConnection;
use App\Models\SprintReview;
use App\Models\SprintReviewFeedback;
use App\Models\SprintReviewItem;
use App\Models\SprintReviewParticipant;
use App\Models\User;
use App\Support\SprintRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sprint Review. O Maestro Scrum abre a review de uma sprint a partir do último dia dela (ou antes, se já estiver fechada
 * no Monday) e conduz seis etapas: abertura, resultado da sprint, demonstração das entregas (com o aceite de cada uma),
 * planejados não entregues (motivo e destino), feedbacks e próximos passos. As entregas vêm da base sincronizada do
 * Monday e a review não muda status de card; só um feedback pode virar demanda no Funil de Demandas, quando alguém pede.
 */
class SprintReviewService
{
    /** Quem consultou a sala nos últimos segundos aparece como presente. */
    public const PRESENCE_SECONDS = 20;

    /** Sprints mostradas no histórico da tela. */
    public const HISTORY_SPRINTS = 8;

    /** Etapas e o tempo previsto de cada uma, em minutos (60 no total). */
    public const STEPS = [
        ['name' => 'Abertura', 'minutes' => 5],
        ['name' => 'Resultado da sprint', 'minutes' => 5],
        ['name' => 'Demonstração', 'minutes' => 30],
        ['name' => 'Não entregue', 'minutes' => 5],
        ['name' => 'Feedbacks', 'minutes' => 10],
        ['name' => 'Próximos passos', 'minutes' => 5],
    ];

    public function __construct(private MondaySnapshotService $snapshots, private PlanningService $planning, private FeatureRequestService $requests) {}

    // ── Tela inicial ─────────────────────────────────────────────────────────────

    /**
     * Resultado das reviews, a review em andamento e as últimas sprints com o resumo de cada review.
     *
     * @return array<string, mixed>
     */
    public function overview(MondayConnection $connection, User $user): array
    {
        $today = SprintRules::today();
        $sprints = $this->sprints($connection)->filter(fn (array $sprint): bool => $sprint['start'] <= $today)->take(self::HISTORY_SPRINTS)->values();
        $reviews = SprintReview::query()->whereIn('sprint', $sprints->pluck('number'))->with(['facilitator', 'decisions', 'feedbacks', 'participants.user'])->get()->keyBy('sprint');
        $closed = SprintReview::query()->where('status', SprintReview::CLOSED)->with(['decisions', 'feedbacks'])->get();
        $open = $this->openReview();
        $verdicts = $closed->flatMap->decisions->countBy('verdict');
        $presented = ($verdicts['accepted'] ?? 0) + ($verdicts['adjust'] ?? 0) + ($verdicts['rejected'] ?? 0);

        return [
            'canFacilitate' => $user->canFacilitateReviews(),
            'kpis' => [
                'reviews' => $closed->count(), 'presented' => $presented, 'accepted' => $verdicts['accepted'] ?? 0, 'adjust' => $verdicts['adjust'] ?? 0,
                'rejected' => $verdicts['rejected'] ?? 0, 'rate' => $presented ? (int) round(($verdicts['accepted'] ?? 0) / $presented * 100) : null,
                'feedbacks' => $closed->flatMap->feedbacks->count(), 'demands' => $closed->flatMap->feedbacks->where('demand_status', 'created')->count(),
                'goalHit' => $closed->where('goal_result', 'yes')->count(), 'goalDecided' => $closed->whereNotNull('goal_result')->count(),
            ],
            'open' => $open ? [
                'sprint' => $open->sprint, 'sprintName' => $open->sprint_name, 'facilitator' => $open->facilitator?->name,
                'present' => $this->names($this->presentParticipants($open)), 'elapsedSeconds' => array_sum($this->stepSeconds($open)),
                'step' => $open->step, 'stepName' => self::STEPS[$open->step]['name'],
            ] : null,
            'sprints' => $sprints->map(fn (array $sprint): array => [
                ...$sprint, 'current' => $sprint['start'] <= $today && $sprint['end'] >= $today, 'startable' => $this->startable($sprint, $today),
                'review' => isset($reviews[$sprint['number']]) ? $this->summary($reviews[$sprint['number']]) : null,
            ])->all(),
        ];
    }

    // ── Sala ─────────────────────────────────────────────────────────────────────

    /**
     * A review em andamento como cada pessoa vê. Consultar a sala também coloca a pessoa nela.
     *
     * @return array<string, mixed>
     */
    public function room(MondayConnection $connection, User $user): array
    {
        $review = $this->openReview();
        if ($review === null) {
            return ['review' => null, 'canFacilitate' => $user->canFacilitateReviews()];
        }
        SprintReviewParticipant::updateOrCreate(['sprint_review_id' => $review->id, 'user_id' => $user->id], ['last_seen_at' => now()]);

        return ['review' => $this->present($connection, $review, $user), 'canFacilitate' => $user->canFacilitateReviews()];
    }

    /**
     * Modo leitura (olhinho). Sem review no app, vêm as entregas e os planejados não entregues da sprint.
     *
     * @return array<string, mixed>
     */
    public function review(MondayConnection $connection, User $user, int $sprint): array
    {
        $info = $this->sprints($connection)->firstWhere('number', $sprint)
            ?? throw new RuntimeException("Sprint {$sprint} não encontrada no cronograma de sprints do Monday.");
        $review = SprintReview::query()->where('sprint', $sprint)->first();

        return [
            'sprint' => $info, 'sprintGoal' => $this->planning->goal($sprint),
            'items' => $review ? [] : $this->liveItems($connection, $sprint),
            'review' => $review ? $this->present($connection, $review, $user) : null,
        ];
    }

    /** Abre a review: a partir do último dia da sprint (ou antes, se ela já foi fechada no Monday). */
    public function start(MondayConnection $connection, User $user, int $sprint): SprintReview
    {
        if (! $user->canFacilitateReviews()) {
            throw new RuntimeException('Só o Maestro Scrum pode iniciar a Sprint Review.');
        }
        $info = $this->sprints($connection)->firstWhere('number', $sprint)
            ?? throw new RuntimeException("Sprint {$sprint} não encontrada no cronograma de sprints do Monday.");
        if (! $this->startable($info, SprintRules::today())) {
            throw new RuntimeException('A review da '.$info['name'].' abre no último dia da sprint ('.Carbon::parse($info['end'])->format('d/m').') ou quando ela for fechada no Monday.');
        }

        return DB::transaction(function () use ($user, $info): SprintReview {
            $open = SprintReview::query()->where('status', SprintReview::OPEN)->lockForUpdate()->first();
            if ($open !== null) {
                throw new RuntimeException("Encerre a review da {$open->sprint_name} antes de iniciar outra.");
            }
            if (SprintReview::query()->where('sprint', $info['number'])->exists()) {
                throw new RuntimeException("A review da {$info['name']} já foi feita. Veja pelo olhinho.");
            }

            return SprintReview::query()->create([
                'sprint' => $info['number'], 'sprint_name' => $info['name'], 'status' => SprintReview::OPEN, 'facilitator_id' => $user->id,
                'step' => 0, 'step_started_at' => now(), 'step_seconds' => [], 'started_at' => now(),
            ]);
        });
    }

    public function step(User $user, SprintReview $review, int $step): void
    {
        $this->conducting($user, $review, function (SprintReview $locked) use ($step): void {
            if ($step < 0 || $step >= count(self::STEPS)) {
                throw new RuntimeException('Etapa inválida.');
            }
            $locked->update(['step_seconds' => $this->stepSeconds($locked), 'step' => $step, 'step_started_at' => now()]);
        });
    }

    /** Coloca uma entrega no palco da demonstração: todos passam a ver a mesma. */
    public function focus(MondayConnection $connection, User $user, SprintReview $review, string $itemId): void
    {
        $this->ensureItem($connection, $review, $itemId);
        $this->conducting($user, $review, fn (SprintReview $locked) => $locked->update(['current_item_id' => $itemId]));
    }

    /**
     * Decisão sobre um card: aceite e observações de uma entrega, ou motivo e destino de um planejado não entregue.
     * Só o facilitador (Maestro Scrum) decide.
     *
     * @param  array{verdict?: ?string, notes?: ?string, reason?: ?string, destination?: ?string}  $fields
     */
    public function decide(MondayConnection $connection, User $user, SprintReview $review, string $itemId, array $fields): void
    {
        $this->ensureItem($connection, $review, $itemId);
        $this->conducting($user, $review, fn (SprintReview $locked) => SprintReviewItem::query()->updateOrCreate(
            ['sprint_review_id' => $locked->id, 'item_id' => $itemId], [...$fields, 'decided_by' => $user->id],
        ));
    }

    /** O objetivo da sprint foi atingido? Sim, parcial ou não, e por quê. */
    public function goal(User $user, SprintReview $review, string $result, ?string $note): void
    {
        $this->conducting($user, $review, fn (SprintReview $locked) => $locked->update(['goal_result' => $result, 'goal_note' => $note]));
    }

    /**
     * Feedback de quem está na review (qualquer participante registra).
     *
     * @param  array{text: string, type: string, given_by?: ?int, item_id?: ?string}  $data
     */
    public function addFeedback(MondayConnection $connection, User $user, SprintReview $review, array $data): void
    {
        $this->ensureOpen($review);
        $item = ! empty($data['item_id']) ? $this->ensureItem($connection, $review, $data['item_id']) : null;
        $review->feedbacks()->create([
            'author_id' => $user->id, 'given_by' => $data['given_by'] ?? $user->id, 'type' => $data['type'], 'text' => trim($data['text']),
            'item_id' => $item['id'] ?? null, 'item_name' => $item['name'] ?? null,
        ]);
    }

    /** Apaga um feedback ainda sem demanda: quem registrou ou o facilitador. */
    public function deleteFeedback(User $user, SprintReviewFeedback $feedback): void
    {
        $review = SprintReview::query()->findOrFail($feedback->sprint_review_id);
        $this->ensureOpen($review);
        if ($feedback->author_id !== $user->id && ! $this->facilitates($user, $review)) {
            throw new RuntimeException('Só quem registrou o feedback ou o facilitador pode apagá-lo.');
        }
        if ($feedback->demand_status === 'created') {
            throw new RuntimeException('Este feedback já virou demanda no Funil de Demandas e não pode ser apagado.');
        }
        $feedback->delete();
    }

    /**
     * Cria a demanda no Funil de Demandas a partir do feedback, uma única vez (permissão "Atualizar cards"). Falha do
     * Monday fica registrada e pode ser repetida.
     */
    public function createDemand(MondayConnection $connection, User $user, SprintReviewFeedback $feedback): void
    {
        if (! $user->can_update_items) {
            throw new RuntimeException('Criar demanda no Monday exige a permissão "Atualizar cards".');
        }
        $claimed = SprintReviewFeedback::query()->whereKey($feedback->id)
            ->where(fn ($query) => $query->whereNull('demand_status')->orWhere('demand_status', 'failed'))
            ->update(['demand_status' => 'sending']);
        if ($claimed === 0) {
            throw new RuntimeException($feedback->fresh()->demand_status === 'created' ? 'Este feedback já virou demanda no Funil de Demandas.' : 'A criação desta demanda já está em andamento.');
        }
        $feedback->refresh()->load(['giver', 'author']);
        $review = SprintReview::query()->findOrFail($feedback->sprint_review_id);
        $requester = $feedback->giver?->name ?? $feedback->author?->name ?? $user->name;
        $description = 'Feedback da Sprint Review da '.$review->sprint_name.' ('.SprintReviewFeedback::TYPES[$feedback->type].').'
            ."\nDe: {$requester}".($feedback->item_name ? "\nEntrega: {$feedback->item_name}" : '')
            ."\nRegistrado no YUV Sprint Metrics por {$user->name}.\n\n".$feedback->text;
        try {
            $created = $this->requests->createFromFeedback($connection, mb_substr($feedback->text, 0, 120), $requester, $description);
        } catch (Throwable $exception) {
            report($exception);
            $feedback->update(['demand_status' => 'failed', 'demand_error' => $exception->getMessage()]);

            return;
        }
        $feedback->update(['demand_status' => 'created', 'demand_item_id' => $created['id'], 'demand_url' => $created['url'] ?: null, 'demand_error' => null]);
        Log::info('Feedback da Sprint Review virou demanda no Funil.', ['feedback' => $feedback->id, 'item' => $created['id'], 'user' => $user->email]);
    }

    /**
     * Encerra a review: congela os indicadores (calculados pela tela com as regras do Dashboard) e a lista de entregas.
     *
     * @param  array<string, mixed>  $metrics
     */
    public function close(MondayConnection $connection, User $user, SprintReview $review, array $metrics): void
    {
        $items = $this->liveItems($connection, $review->sprint);
        $this->conducting($user, $review, fn (SprintReview $locked) => $locked->update([
            'step_seconds' => $this->stepSeconds($locked), 'step_started_at' => null, 'status' => SprintReview::CLOSED,
            'closed_at' => now(), 'metrics' => $metrics, 'items' => $items, 'current_item_id' => null,
        ]));
    }

    /**
     * Resumo da review de uma sprint para outras telas (etapa Dados da retrospectiva).
     *
     * @return array<string, mixed>|null
     */
    public function summaryFor(int $sprint): ?array
    {
        $review = SprintReview::query()->where('sprint', $sprint)->with(['decisions', 'feedbacks', 'participants.user'])->first();

        return $review ? $this->summary($review) : null;
    }

    // ── Apoio ────────────────────────────────────────────────────────────────────

    /**
     * Entregas (cards Implementado) e planejados não entregues da sprint, na base sincronizada do Monday.
     *
     * @return list<array<string, mixed>>
     */
    private function liveItems(MondayConnection $connection, int $sprint): array
    {
        return collect($this->snapshots->latest($connection, PHP_INT_MAX)['rows'] ?? [])
            ->filter(fn (array $row): bool => (int) ($row['Sprint'] ?? 0) === $sprint && ! SprintRules::isRemoved($row['Status'] ?? null) && ! empty($row['Id']))
            ->unique('Id')
            ->map(function (array $row): ?array {
                $done = SprintRules::isDone($row['Status'] ?? null);
                $planned = SprintRules::normalize($row['Planejada'] ?? '') === 'planejada' && SprintRules::normalize($row['Tipo'] ?? '') !== 'suporte';
                if (! $done && ! $planned) {
                    return null;
                }

                return [
                    'id' => (string) $row['Id'], 'name' => (string) ($row['Nome'] ?? ''), 'url' => ($row['Url'] ?? '') ?: null, 'kind' => $done ? 'delivered' : 'pending',
                    'type' => ($row['Tipo'] ?? '') ?: null, 'epic' => ($row['Epico'] ?? '') ?: null, 'owner' => ($row['Responsavel'] ?? '') ?: null,
                    'status' => ($row['Status'] ?? '') ?: null, 'estimate' => $row['EstH'] ?? null, 'real' => $row['RealH'] ?? null, 'planned' => $planned,
                    'description' => mb_substr(trim((string) ($row['Descricao'] ?? '')), 0, 800) ?: null,
                ];
            })->filter()
            ->sortBy(fn (array $item): string => ($item['kind'] === 'delivered' ? '0' : '1').mb_strtolower(($item['epic'] ?? $item['type'] ?? 'zz').'|'.$item['name']))
            ->values()->all();
    }

    /**
     * Card da review (entregue ou planejado não entregue).
     *
     * @return array<string, mixed>
     */
    private function ensureItem(MondayConnection $connection, SprintReview $review, string $itemId): array
    {
        $items = $review->isOpen() ? $this->liveItems($connection, $review->sprint) : ($review->items ?? []);

        return collect($items)->firstWhere('id', $itemId) ?? throw new RuntimeException('Este card não é uma entrega nem um planejado da sprint.');
    }

    /** A review abre no último dia da sprint ou antes, se a sprint já foi fechada no Monday. */
    private function startable(array $sprint, string $today): bool
    {
        return $sprint['end'] <= $today || ($sprint['completed'] ?? false);
    }

    /**
     * @return Collection<int, array{number: int, name: string, start: string, end: string, completed: bool}>
     */
    private function sprints(MondayConnection $connection): Collection
    {
        return collect($this->snapshots->latest($connection, PHP_INT_MAX)['sprints'] ?? [])
            ->map(fn (array $sprint): array => ['number' => (int) $sprint['number'], 'name' => (string) $sprint['name'], 'start' => (string) $sprint['start'], 'end' => (string) $sprint['end'], 'completed' => (bool) ($sprint['completed'] ?? false)])
            ->sortByDesc('number')->values();
    }

    private function openReview(): ?SprintReview
    {
        return SprintReview::query()->where('status', SprintReview::OPEN)->with('facilitator')->first();
    }

    /** Facilitador: quem abriu ou outro Maestro Scrum (para a sala não travar se ele sair). */
    private function facilitates(User $user, SprintReview $review): bool
    {
        return $review->facilitator_id === $user->id || $user->canFacilitateReviews();
    }

    private function ensureOpen(SprintReview $review): void
    {
        if (! $review->isOpen()) {
            throw new RuntimeException('Esta review já foi encerrada.');
        }
    }

    private function conducting(User $user, SprintReview $review, callable $callback): void
    {
        DB::transaction(function () use ($user, $review, $callback): void {
            $locked = SprintReview::query()->lockForUpdate()->findOrFail($review->id);
            $this->ensureOpen($locked);
            if (! $this->facilitates($user, $locked)) {
                throw new RuntimeException('Só o Maestro Scrum conduz a review e marca o aceite das entregas.');
            }
            $callback($locked);
        });
    }

    /**
     * @return array<string, int>
     */
    private function stepSeconds(SprintReview $review): array
    {
        $seconds = $review->step_seconds ?? [];
        if ($review->step_started_at !== null) {
            $seconds[(string) $review->step] = ($seconds[(string) $review->step] ?? 0) + (int) $review->step_started_at->diffInSeconds(now(), true);
        }

        return $seconds;
    }

    /**
     * @return Collection<int, SprintReviewParticipant>
     */
    private function presentParticipants(SprintReview $review): Collection
    {
        return $review->participants()->with('user')->where('last_seen_at', '>=', now()->subSeconds(self::PRESENCE_SECONDS))->get();
    }

    /**
     * @param  Collection<int, SprintReviewParticipant>  $participants
     * @return list<string>
     */
    private function names(Collection $participants): array
    {
        return $participants->map(fn (SprintReviewParticipant $participant): ?string => $participant->user?->name)->filter()->sort()->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(SprintReview $review): array
    {
        $verdicts = $review->decisions->countBy('verdict');

        return [
            'id' => $review->id, 'status' => $review->status, 'date' => $review->started_at->toDateString(), 'facilitator' => $review->facilitator?->name,
            'step' => $review->step, 'stepName' => self::STEPS[$review->step]['name'],
            'attendees' => $this->names($review->participants), 'durationSeconds' => array_sum($this->stepSeconds($review)),
            'goalResult' => $review->goal_result,
            'delivered' => $review->items !== null ? collect($review->items)->where('kind', 'delivered')->count() : null,
            'verdicts' => ['accepted' => $verdicts['accepted'] ?? 0, 'adjust' => $verdicts['adjust'] ?? 0, 'rejected' => $verdicts['rejected'] ?? 0, 'skipped' => $verdicts['skipped'] ?? 0],
            'feedbacks' => $review->feedbacks->count(), 'demands' => $review->feedbacks->where('demand_status', 'created')->count(),
        ];
    }

    /**
     * A review completa como a pessoa vê.
     *
     * @return array<string, mixed>
     */
    private function present(MondayConnection $connection, SprintReview $review, User $user): array
    {
        $review->loadMissing(['facilitator', 'decisions', 'feedbacks.giver', 'feedbacks.author', 'participants.user']);
        $decisions = $review->decisions->keyBy('item_id');
        $items = collect($review->isOpen() ? $this->liveItems($connection, $review->sprint) : ($review->items ?? []))
            ->map(fn (array $item): array => [...$item, 'verdict' => $decisions[$item['id']]->verdict ?? null, 'notes' => $decisions[$item['id']]->notes ?? null,
                'reason' => $decisions[$item['id']]->reason ?? null, 'destination' => $decisions[$item['id']]->destination ?? null])->all();
        $stepSeconds = $this->stepSeconds($review);

        return [
            'id' => $review->id, 'sprint' => $review->sprint, 'sprintName' => $review->sprint_name, 'status' => $review->status,
            'facilitator' => $review->facilitator ? ['id' => $review->facilitator->id, 'name' => $review->facilitator->name] : null,
            'canConduct' => $review->isOpen() && $this->facilitates($user, $review),
            'canCreateDemands' => (bool) $user->can_update_items,
            'me' => ['id' => $user->id, 'name' => $user->name],
            'step' => $review->step,
            'steps' => collect(self::STEPS)->map(fn (array $step, int $index): array => [...$step, 'seconds' => $stepSeconds[(string) $index] ?? 0])->all(),
            'elapsedSeconds' => array_sum($stepSeconds),
            'startedAt' => $review->started_at->toIso8601String(), 'closedAt' => $review->closed_at?->toIso8601String(),
            'currentItemId' => $review->current_item_id,
            'sprintGoal' => $this->planning->goal($review->sprint),
            'goalResult' => $review->goal_result, 'goalNote' => $review->goal_note,
            'items' => $items,
            'feedbacks' => $review->feedbacks->map(fn (SprintReviewFeedback $feedback): array => [
                'id' => $feedback->id, 'type' => $feedback->type, 'typeLabel' => SprintReviewFeedback::TYPES[$feedback->type] ?? $feedback->type,
                'text' => $feedback->text, 'givenBy' => $feedback->giver?->name, 'author' => $feedback->author?->name,
                'item' => $feedback->item_id ? ['id' => $feedback->item_id, 'name' => $feedback->item_name] : null,
                'demand' => ['status' => $feedback->demand_status, 'id' => $feedback->demand_item_id, 'url' => $feedback->demand_url, 'error' => $feedback->demand_error],
                'canDelete' => $review->isOpen() && $feedback->demand_status !== 'created' && ($feedback->author_id === $user->id || $this->facilitates($user, $review)),
            ])->all(),
            'metrics' => $review->metrics,
            'participants' => $review->isOpen() ? $this->presentParticipants($review)->map(fn (SprintReviewParticipant $participant): array => ['id' => $participant->user_id, 'name' => $participant->user?->name])->values()->all() : [],
            'attendees' => $this->names($review->participants),
            'users' => $review->isOpen() ? User::query()->orderBy('name')->get(['id', 'name'])->map(fn (User $person): array => ['id' => $person->id, 'name' => $person->name])->all() : [],
        ];
    }
}
