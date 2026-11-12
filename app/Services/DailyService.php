<?php

namespace App\Services;

use App\Models\DailyAction;
use App\Models\DailyActionEvent;
use App\Models\DailyParticipant;
use App\Models\DailyScrum;
use App\Models\MondayConnection;
use App\Models\User;
use App\Support\SprintRules;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Daily Hub: o acompanhamento diário da sprint. Os cards, os status e as horas continuam vindo do Monday (a tela usa a
 * base sincronizada); aqui ficam só as Dailies e o To-Do cumulativo de ações: uma ação pendente segue aparecendo nas
 * próximas Dailies da sprint, sem ser duplicada, até alguém concluir. Concluir não mexe no status do card. Com card
 * vinculado, a abertura é avisada no card como pendente e a resolução pode ser publicada; nenhum dos dois duplica.
 */
class DailyService
{
    /** Quem consultou a Daily nos últimos segundos está presente (a tela consulta a presença a cada 10 segundos). */
    public const PRESENCE_SECONDS = 30;

    public function __construct(private MondayService $monday, private MondaySnapshotService $snapshots, private PlanningService $planning, private RetrospectiveService $retrospectives) {}

    /**
     * A Daily de uma sprint num dia: objetivo da sprint, Dailies já feitas, ações (as abertas até aquele dia, com o
     * histórico de cada uma), usuários que podem ser responsáveis e há quanto tempo cada card aberto está no status
     * atual (tempo de impedimento, pelo histórico do Monday).
     *
     * @return array<string, mixed>
     */
    public function state(MondayConnection $connection, User $user, ?int $sprint = null, ?string $date = null): array
    {
        $snapshot = $this->snapshots->latest($connection, PHP_INT_MAX);
        $today = SprintRules::today();
        $date ??= $today;
        $sprints = collect($snapshot['sprints'] ?? [])->sortByDesc('start')->values();
        $sprint ??= (int) ($sprints->first(fn (array $item): bool => $item['start'] <= $today && $item['end'] >= $today)['number']
            ?? $sprints->first(fn (array $item): bool => $item['start'] <= $today)['number'] ?? 0);
        // Abrir a Daily de hoje da sprint atual já conta presença (e cria a Daily do dia, se for a primeira pessoa).
        if ($date === $today) {
            $this->join($snapshot, $user, $sprint);
        }
        $dailies = DailyScrum::query()->where('sprint', $sprint)->withCount(['actions', 'actions as done_count' => fn ($query) => $query->where('status', DailyAction::DONE)])
            ->with('participants.user')->orderBy('date')->get();
        $selected = $dailies->first(fn (DailyScrum $daily): bool => $daily->date->toDateString() === $date);
        $actions = DailyAction::query()->where('sprint', $sprint)
            ->whereHas('daily', fn ($query) => $query->whereDate('date', '<=', $date))
            ->with(['daily', 'responsible', 'opener', 'closer', 'events.user'])->orderBy('opened_at')->get();

        return [
            'sprint' => $sprint,
            'sprints' => $sprints->filter(fn (array $item): bool => $item['start'] <= $today)
                ->map(fn (array $item): array => ['number' => (int) $item['number'], 'name' => $item['name'], 'start' => $item['start'], 'end' => $item['end']])->values()->all(),
            'date' => $date,
            'today' => $today,
            'goal' => $this->planning->goal($sprint),
            'dailies' => $dailies->map(fn (DailyScrum $daily): array => [
                'date' => $daily->date->toDateString(), 'actions' => $daily->actions_count, 'done' => $daily->done_count, ...$this->presenceOf($daily),
            ])->all(),
            'presence' => $selected ? $this->presenceOf($selected) : null,
            'actions' => $actions->map(fn (DailyAction $action): array => $this->present($action))->all(),
            'users' => User::query()->orderBy('name')->get(['id', 'name'])->map(fn (User $person): array => ['id' => $person->id, 'name' => $person->name])->all(),
            'statusAges' => $this->statusAges($snapshot, $connection, $sprint, min($date, $today)),
            'canPublish' => $user->canPublishDailyResolutions(),
            // Ações das retrospectivas ainda abertas, de qualquer sprint: lembradas em todas as Dailies até serem concluídas.
            'retroActions' => $this->retrospectives->openActions($user),
            'syncedAt' => $snapshot['syncedAt'] ?? null,
        ];
    }

    /**
     * Presença na Daily de hoje: quem está com a tela aberta agora, quem já passou por ela e o tempo da Daily. A tela
     * chama a cada poucos segundos enquanto está na Daily de hoje da sprint atual.
     *
     * @return array{present: list<string>, attendees: list<string>, startedAt: ?string, seconds: int, live: bool}|null
     */
    public function presence(MondayConnection $connection, User $user, int $sprint): ?array
    {
        $daily = $this->join($this->snapshots->latest($connection, PHP_INT_MAX), $user, $sprint);

        return $daily ? $this->presenceOf($daily) : null;
    }

    /**
     * Coloca a pessoa na Daily de hoje, só quando a sprint é a atual (Dailies de sprints passadas são só consulta).
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function join(array $snapshot, User $user, int $sprint): ?DailyScrum
    {
        $today = SprintRules::today();
        $current = collect($snapshot['sprints'] ?? [])->contains(fn (array $item): bool => (int) $item['number'] === $sprint && $item['start'] <= $today && $item['end'] >= $today);
        if (! $current) {
            return null;
        }
        $daily = $this->todayDaily($sprint, $user);
        // Gravação atômica: duas abas ou duas pessoas ao mesmo tempo não duplicam a presença; a primeira entrada fica.
        DailyParticipant::query()->upsert(
            [['daily_scrum_id' => $daily->id, 'user_id' => $user->id, 'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]],
            ['daily_scrum_id', 'user_id'], ['last_seen_at', 'updated_at'],
        );

        return $daily;
    }

    /** A Daily de hoje da sprint, criada pela primeira pessoa que abre a tela ou registra uma ação. */
    private function todayDaily(int $sprint, User $user): DailyScrum
    {
        $today = SprintRules::today();
        $find = fn (): ?DailyScrum => DailyScrum::query()->where('sprint', $sprint)->whereDate('date', $today)->first();
        try {
            return $find() ?? DailyScrum::query()->create(['sprint' => $sprint, 'date' => $today, 'created_by' => $user->id]);
        } catch (UniqueConstraintViolationException) {
            return $find();
        }
    }

    /**
     * Presentes e tempo de uma Daily: começa na primeira entrada e termina na última saída; enquanto alguém está na tela,
     * o tempo continua contando.
     *
     * @return array{present: list<string>, attendees: list<string>, startedAt: ?string, seconds: int, live: bool}
     */
    public function presenceOf(DailyScrum $daily): array
    {
        $people = $daily->relationLoaded('participants') ? $daily->participants : $daily->participants()->with('user')->get();
        if ($people->isEmpty()) {
            return ['present' => [], 'attendees' => [], 'startedAt' => null, 'seconds' => 0, 'live' => false];
        }
        $cut = now()->subSeconds(self::PRESENCE_SECONDS);
        $present = $people->filter(fn (DailyParticipant $person): bool => $person->last_seen_at->gte($cut));
        $start = $people->min('first_seen_at');
        $end = $present->isNotEmpty() ? now() : $people->max('last_seen_at');
        $names = fn ($list): array => $list->map(fn (DailyParticipant $person): ?string => $person->user?->name)->filter()->sort()->values()->all();

        return ['present' => $names($present), 'attendees' => $names($people), 'startedAt' => $start->toIso8601String(), 'seconds' => (int) $start->diffInSeconds($end, true), 'live' => $present->isNotEmpty()];
    }

    /**
     * Registra uma ação na Daily de hoje da sprint (a Daily é criada na primeira ação do dia). O card do Monday é
     * opcional e precisa estar na base sincronizada. Com card e a permissão Atualizar cards, a responsabilidade é
     * avisada no card como pendente; sem a permissão, fica registrada só no app.
     */
    public function open(MondayConnection $connection, User $user, int $sprint, string $description, ?int $responsibleId, ?string $itemId): DailyAction
    {
        $item = null;
        if ($itemId !== null) {
            $row = collect($this->snapshots->latest($connection, PHP_INT_MAX)['rows'] ?? [])->first(fn (array $row): bool => (string) ($row['Id'] ?? '') === $itemId)
                ?? throw new RuntimeException('Card não encontrado nos dados sincronizados do Monday. Atualize os dados e tente de novo.');
            $item = ['item_id' => $itemId, 'item_name' => (string) ($row['Nome'] ?? ''), 'item_url' => ($row['Url'] ?? '') ?: null];
        }

        $action = DB::transaction(function () use ($user, $sprint, $description, $responsibleId, $item): DailyAction {
            $daily = $this->todayDaily($sprint, $user);
            $action = $daily->actions()->create([
                'sprint' => $sprint, 'description' => trim($description), 'responsible_id' => $responsibleId,
                ...($item ?? []), 'status' => DailyAction::PENDING, 'opened_by' => $user->id, 'opened_at' => now(),
            ]);
            $action->events()->create(['type' => DailyActionEvent::OPENED, 'user_id' => $user->id]);

            return $action;
        });
        if ($item !== null && $user->canPublishDailyResolutions()) {
            $this->notice($connection, $user, $action);
        }

        return $action;
    }

    /**
     * Comenta no card que a responsabilidade foi aberta e está pendente. Só envia uma vez por responsabilidade: duas
     * requisições ou um novo clique não duplicam. Falha do Monday não desfaz a responsabilidade e pode ser repetida.
     */
    public function notice(MondayConnection $connection, User $user, DailyAction $action): DailyAction
    {
        if (! $user->canPublishDailyResolutions()) {
            throw new RuntimeException('Comentar no card do Monday exige a permissão "Atualizar cards".');
        }
        if ($action->item_id === null) {
            throw new RuntimeException('Só uma responsabilidade ligada a um card pode ser avisada no Monday.');
        }
        $claimed = DailyAction::query()->whereKey($action->id)
            ->where(fn ($query) => $query->whereNull('notice_status')->orWhere('notice_status', DailyAction::PUBLISH_FAILED))
            ->update(['notice_status' => 'sending']);
        if ($claimed === 0) {
            throw new RuntimeException($action->fresh()->notice_status === DailyAction::PUBLISHED ? 'Esta responsabilidade já foi avisada no card.' : 'O aviso desta responsabilidade já está em andamento.');
        }
        $action->refresh()->load(['daily', 'responsible', 'opener']);
        try {
            $updateId = $this->monday->postHtmlComment((string) $connection->token, [(string) $connection->board_id], (string) $action->item_id, $this->noticeHtml($action), $user->name);
        } catch (Throwable $exception) {
            report($exception);
            $action->update(['notice_status' => DailyAction::PUBLISH_FAILED]);
            $action->events()->create(['type' => DailyActionEvent::NOTICE_FAILED, 'user_id' => $user->id, 'error' => $exception->getMessage()]);

            return $action;
        }
        $action->update(['notice_status' => DailyAction::PUBLISHED, 'notice_update_id' => $updateId]);
        $action->events()->create(['type' => DailyActionEvent::NOTICED, 'user_id' => $user->id, 'monday_update_id' => $updateId]);
        Log::info('Responsabilidade da Daily avisada no card.', ['action' => $action->id, 'item' => $action->item_id, 'update' => $updateId]);

        return $action;
    }

    /** Comentário de abertura no mesmo modelo da resolução, com o status pendente. */
    public function noticeHtml(DailyAction $action): string
    {
        $line = fn (string $label, string $value): string => "<p>{$label} ".nl2br(e($value), false).'</p>';

        return '<p><strong>📋 DAILY SCRUM — NOVA PENDÊNCIA</strong></p>'
            .$line('🔴 <strong>Questão:</strong>', $action->description)
            .$line('👤 <strong>Responsável:</strong>', $action->responsible?->name ?? 'Não informado')
            .$line('📅 <strong>Abertura:</strong>', $action->opened_at->copy()->setTimezone(SprintRules::TIMEZONE)->format('d/m/Y H:i')
                .' · Daily de '.$action->daily->date->format('d/m').' · Sprint '.$action->sprint)
            .$line('🙋 <strong>Aberta por:</strong>', $action->opener?->name ?? 'Não informado')
            .'<p>⏳ <strong>Status:</strong> Pendente</p>'
            .'<p>A resolução será comentada aqui quando a responsabilidade for concluída no YUV Sprint Metrics.</p>';
    }

    /**
     * Conclui a ação com a resolução. Com card vinculado e `publish`, publica a resolução no card (exige a permissão
     * Atualizar cards). Se o Monday falhar, a ação continua concluída e a publicação fica pendente para nova tentativa.
     * Não altera o status do card no Monday.
     */
    public function complete(MondayConnection $connection, User $user, DailyAction $action, string $resolution, bool $publish): DailyAction
    {
        $publish = $publish && $action->item_id !== null;
        if ($publish && ! $user->canPublishDailyResolutions()) {
            throw new RuntimeException('Publicar no card do Monday exige a permissão "Atualizar cards". Conclua sem publicar ou peça a permissão.');
        }
        DB::transaction(function () use ($user, $action, $resolution, $publish): void {
            $locked = DailyAction::query()->lockForUpdate()->findOrFail($action->id);
            if ($locked->isDone()) {
                throw new RuntimeException('Esta ação já foi concluída.');
            }
            $locked->update([
                'status' => DailyAction::DONE, 'closed_at' => now(), 'closed_by' => $user->id, 'resolution' => trim($resolution),
                'publish_status' => $publish ? DailyAction::PUBLISH_PENDING : null, 'monday_update_id' => null,
            ]);
            $locked->events()->create(['type' => DailyActionEvent::DONE, 'user_id' => $user->id, 'resolution' => trim($resolution)]);
        });
        $action->refresh();
        if ($publish) {
            $this->publish($connection, $user, $action);
        }

        return $action->refresh();
    }

    /**
     * Publica no card a conclusão atual da ação. Só publica quando a conclusão ainda não foi publicada: dois cliques ou
     * duas abas não geram comentário duplicado. Falha do Monday fica registrada para nova tentativa.
     */
    public function publish(MondayConnection $connection, User $user, DailyAction $action): DailyAction
    {
        if (! $user->canPublishDailyResolutions()) {
            throw new RuntimeException('Publicar no card do Monday exige a permissão "Atualizar cards".');
        }
        if (! $action->isDone() || $action->item_id === null) {
            throw new RuntimeException('Só uma ação concluída e ligada a um card pode ser publicada no Monday.');
        }
        // Reserva a publicação: só uma requisição passa daqui para a mesma conclusão.
        $claimed = DailyAction::query()->whereKey($action->id)->where('status', DailyAction::DONE)
            ->where(fn ($query) => $query->whereNull('publish_status')->orWhereIn('publish_status', [DailyAction::PUBLISH_PENDING, DailyAction::PUBLISH_FAILED]))
            ->update(['publish_status' => 'sending']);
        if ($claimed === 0) {
            throw new RuntimeException($action->fresh()->publish_status === DailyAction::PUBLISHED ? 'Esta conclusão já foi publicada no card.' : 'A publicação desta ação já está em andamento.');
        }
        $action->refresh();
        try {
            $updateId = $this->monday->postHtmlComment((string) $connection->token, [(string) $connection->board_id], (string) $action->item_id, $this->commentHtml($action, $action->closed_at ?? now(), (string) $action->resolution), $user->name);
        } catch (Throwable $exception) {
            report($exception);
            $action->update(['publish_status' => DailyAction::PUBLISH_FAILED]);
            $action->events()->create(['type' => DailyActionEvent::PUBLISH_FAILED, 'user_id' => $user->id, 'error' => $exception->getMessage()]);

            return $action;
        }
        $action->update(['publish_status' => DailyAction::PUBLISHED, 'monday_update_id' => $updateId]);
        $action->events()->create(['type' => DailyActionEvent::PUBLISHED, 'user_id' => $user->id, 'monday_update_id' => $updateId]);
        Log::info('Resolução de ação da Daily publicada no card.', ['action' => $action->id, 'item' => $action->item_id, 'update' => $updateId]);

        return $action;
    }

    /** Reabre a ação: volta às pendências; a conclusão anterior (e a publicação dela) fica no histórico. */
    public function reopen(User $user, DailyAction $action): DailyAction
    {
        return DB::transaction(function () use ($user, $action): DailyAction {
            $locked = DailyAction::query()->lockForUpdate()->findOrFail($action->id);
            if (! $locked->isDone()) {
                throw new RuntimeException('Esta ação já está pendente.');
            }
            $locked->update(['status' => DailyAction::PENDING, 'closed_at' => null, 'closed_by' => null, 'resolution' => null, 'publish_status' => null, 'monday_update_id' => null]);
            $locked->events()->create(['type' => DailyActionEvent::REOPENED, 'user_id' => $user->id]);

            return $locked;
        });
    }

    /** Prévia do comentário que vai para o card, com a resolução digitada e a conclusão agora. */
    public function preview(DailyAction $action, string $resolution): string
    {
        return $this->commentHtml($action, now(), $resolution);
    }

    /**
     * Comentário de resolução no modelo da Daily (emojis e negrito preservados; textos escapados).
     */
    public function commentHtml(DailyAction $action, CarbonInterface $closedAt, string $resolution): string
    {
        $when = fn (CarbonInterface $at): string => $at->copy()->setTimezone(SprintRules::TIMEZONE)->format('d/m/Y H:i');
        $line = fn (string $label, string $value): string => "<p>{$label} ".nl2br(e($value), false).'</p>';

        return '<p><strong>📋 DAILY SCRUM — RESOLUÇÃO DE PENDÊNCIA</strong></p>'
            .$line('🔴 <strong>Questão:</strong>', $action->description)
            .$line('👤 <strong>Responsável:</strong>', $action->responsible?->name ?? 'Não informado')
            .$line('📅 <strong>Abertura:</strong>', $when($action->opened_at))
            .$line('✅ <strong>Conclusão:</strong>', $when($closedAt))
            .$line('⏱️ <strong>Tempo decorrido:</strong>', self::elapsed($action->opened_at, $closedAt))
            .$line('🛠️ <strong>Resolução:</strong>', trim($resolution))
            .'<p>✅ Ação concluída e registrada no YUV Sprint Metrics.</p>';
    }

    /** Tempo corrido entre abertura e conclusão, em dias, horas e minutos (ex.: "1d 3h 05min"). */
    public static function elapsed(CarbonInterface $from, CarbonInterface $to): string
    {
        $minutes = max(0, (int) floor($from->diffInMinutes($to, true)));
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $rest = $minutes % 60;

        return trim(($days ? "{$days}d " : '').(($days || $hours) ? "{$hours}h " : '').sprintf('%02dmin', $rest));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DailyAction $action): array
    {
        $at = fn (?Carbon $value): ?string => $value?->toIso8601String();

        return [
            'id' => $action->id, 'description' => $action->description, 'origin' => $action->daily->date->toDateString(),
            'responsible' => $action->responsible ? ['id' => $action->responsible->id, 'name' => $action->responsible->name] : null,
            'item' => $action->item_id ? ['id' => $action->item_id, 'name' => $action->item_name, 'url' => $action->item_url] : null,
            'status' => $action->status, 'openedAt' => $at($action->opened_at), 'openedBy' => $action->opener?->name,
            'closedAt' => $at($action->closed_at), 'closedBy' => $action->closer?->name, 'resolution' => $action->resolution,
            'elapsed' => $action->closed_at ? self::elapsed($action->opened_at, $action->closed_at) : null,
            'publishStatus' => $action->publish_status, 'mondayUpdateId' => $action->monday_update_id,
            'noticeStatus' => $action->notice_status, 'noticeUpdateId' => $action->notice_update_id,
            'reopened' => $action->events->where('type', DailyActionEvent::REOPENED)->count(),
            'events' => $action->events->map(fn (DailyActionEvent $event): array => [
                'type' => $event->type, 'at' => $at($event->created_at), 'user' => $event->user?->name,
                'resolution' => $event->resolution, 'mondayUpdateId' => $event->monday_update_id, 'error' => $event->error,
            ])->all(),
        ];
    }

    /**
     * Desde quando cada card aberto da sprint está no status atual, pelo histórico do Monday. Para um card impedido, é
     * o tempo de impedimento; sem histórico confiável, `since` vem nulo e a tela não calcula o tempo.
     *
     * @return array<string, array{since: ?string, workingDays: ?int}>
     */
    private function statusAges(array $snapshot, MondayConnection $connection, int $sprint, string $today): array
    {
        if ($sprint === 0 || ($snapshot['rows'] ?? []) === []) {
            return [];
        }
        try {
            $ages = SprintTimeline::fromDatabase($snapshot, $connection)->statusAges($sprint, $today);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        return collect($ages)->mapWithKeys(fn (array $age): array => [$age['id'] => ['since' => $age['since'], 'workingDays' => $age['workingDays']]])->all();
    }

    /**
     * Dailies de uma sprint para o histórico: cada Daily com as ações abertas nela, concluídas, pendentes,
     * responsáveis, cards, tempo de resolução, reaberturas e publicações no Monday.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $sprint): array
    {
        return DailyScrum::query()->where('sprint', $sprint)->orderBy('date')
            ->with(['actions' => fn ($query) => $query->orderBy('opened_at'), 'actions.daily', 'actions.responsible', 'actions.opener', 'actions.closer', 'actions.events.user', 'participants.user'])
            ->get()->map(fn (DailyScrum $daily): array => [
                'date' => $daily->date->toDateString(),
                ...$this->presenceOf($daily),
                'actions' => $daily->actions->map(fn (DailyAction $action): array => $this->present($action))->all(),
            ])->all();
    }
}
