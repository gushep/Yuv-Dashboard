<?php

namespace App\Services;

use App\Models\MondayActivityEvent;
use App\Models\MondayConnection;
use App\Support\SprintRules;
use Carbon\CarbonImmutable;

/**
 * Reconstitui o estado dos cards em qualquer momento a partir do log de atividades copiado do Monday.
 * Sem eventos para um campo, vale o valor atual do card. O histórico só existe a partir do primeiro evento copiado.
 */
class SprintTimeline
{
    /** Campos de status/rótulo reconstituídos pelo texto do rótulo. */
    private const LABEL_FIELDS = ['Status', 'Planejada', 'Tipo', 'Prioridade'];

    /** Campos numéricos reconstituídos pelo valor. */
    private const NUMBER_FIELDS = ['EstH', 'RealH'];

    /** Dias úteis de planejamento: a linha de base do escopo é o fim do último deles. */
    public const PLANNING_DAYS = 2;

    /** @var array<string, array<string, list<array{at: CarbonImmutable, before: mixed, after: mixed, hasBefore: bool}>>> */
    private array $changes = [];

    /** @var array<string, CarbonImmutable> */
    private array $createdAt = [];

    /** @var array<string, CarbonImmutable> */
    private array $deletedAt = [];

    /** @var array<string, string> */
    private array $eventNames = [];

    private ?CarbonImmutable $syncedAt = null;

    /** @var array<string, array<string, mixed>> */
    private array $rows = [];

    /** @var array<string, list<int>> */
    private array $currentSprints = [];

    private ?CarbonImmutable $historyStart = null;

    /**
     * @param  array{rows: list<array<string, mixed>>, sprints?: list<array<string, mixed>>, sprintItems?: array<string, int>, labels?: array<string, array<string, string>>}  $snapshot
     * @param  array<string, string>  $columnMap
     * @param  iterable<MondayActivityEvent>  $events  eventos ordenados por occurred_at
     */
    public function __construct(private array $snapshot, array $columnMap, iterable $events)
    {
        $this->syncedAt = isset($snapshot['syncedAt']) ? CarbonImmutable::parse($snapshot['syncedAt'])->utc() : null;
        foreach ($snapshot['rows'] as $row) {
            $id = (string) ($row['Id'] ?? '');
            if ($id === '') {
                continue;
            }
            $this->rows[$id] ??= $row;
            $this->currentSprints[$id][] = (int) $row['Sprint'];
        }
        $fieldByColumn = [];
        foreach ([...self::LABEL_FIELDS, ...self::NUMBER_FIELDS, 'Sprint'] as $field) {
            if (! empty($columnMap[$field])) {
                $fieldByColumn[$columnMap[$field]] = $field;
            }
        }
        foreach ($events as $event) {
            $this->record($event, $fieldByColumn);
        }
    }

    public static function fromDatabase(array $snapshot, MondayConnection $connection): self
    {
        $events = MondayActivityEvent::query()
            ->where('board_id', (string) $connection->board_id)
            ->where('item_id', '!=', '')
            ->orderBy('occurred_at')
            ->get(['item_id', 'event', 'column_id', 'previous_value', 'value', 'data', 'occurred_at']);

        return new self($snapshot, $connection->column_map ?? [], $events);
    }

    /** Primeiro momento coberto pelo histórico copiado; antes dele vale o estado atual. */
    public function historyStart(): ?CarbonImmutable
    {
        return $this->historyStart;
    }

    /**
     * Estado do card no momento informado, ou null se o card ainda não existia.
     *
     * @return array<string, mixed>|null
     */
    public function stateAt(string $itemId, CarbonImmutable $at): ?array
    {
        if (! $this->existsAt($itemId, $at)) {
            return null;
        }
        $row = $this->rows[$itemId] ?? ['Id' => $itemId, 'Nome' => $this->eventNames[$itemId] ?? 'Card sem dados atuais', 'Status' => '', 'Url' => ''];
        foreach ([...self::LABEL_FIELDS, ...self::NUMBER_FIELDS] as $field) {
            $row[$field] = $this->valueAt($itemId, $field, $at, $row[$field] ?? null);
        }

        return $row;
    }

    public function isMemberAt(string $itemId, int $sprint, CarbonImmutable $at): bool
    {
        if (! $this->existsAt($itemId, $at)) {
            return false;
        }
        // Do momento do sync em diante, vale o que o Monday mostra agora.
        if ($this->syncedAt !== null && $at->gte($this->syncedAt)) {
            return in_array($sprint, $this->currentSprints[$itemId] ?? [], true);
        }

        return in_array($sprint, $this->valueAt($itemId, 'Sprint', $at, $this->currentSprints[$itemId] ?? []), true);
    }

    /**
     * Cards que estão ou já estiveram na sprint.
     *
     * @return list<string>
     */
    public function candidates(int $sprint): array
    {
        $ids = [];
        foreach ($this->currentSprints as $id => $sprints) {
            if (in_array($sprint, $sprints, true)) {
                $ids[$id] = true;
            }
        }
        foreach ($this->changes as $id => $fields) {
            foreach ($fields['Sprint'] ?? [] as $change) {
                if (in_array($sprint, (array) $change['before'], true) || in_array($sprint, (array) $change['after'], true)) {
                    $ids[$id] = true;
                }
            }
        }

        return array_map('strval', array_keys($ids));
    }

    /**
     * Cards da sprint no momento informado, com o estado daquele momento.
     *
     * @return array<string, array<string, mixed>>
     */
    public function membersAt(int $sprint, CarbonImmutable $at): array
    {
        $members = [];
        foreach ($this->candidates($sprint) as $id) {
            if ($this->isMemberAt($id, $sprint, $at) && ($state = $this->stateAt($id, $at)) !== null) {
                $members[$id] = $state;
            }
        }

        return $members;
    }

    /**
     * Série diária (fim de cada dia útil) de escopo e entregas da sprint, até hoje,
     * também separada em planejado, não planejado e suporte conforme o card estava naquele dia.
     *
     * @return list<array{date: string, scope: int, done: int, scopeHours: float, doneHours: float, categories: array<string, array{scope: int, done: int}>}>
     */
    public function burnup(int $sprint, string $start, string $end, ?string $today = null): array
    {
        $today ??= SprintRules::today();
        $points = [];
        foreach (SprintRules::workingDays($start, min($end, $today)) as $day) {
            $scope = $done = 0;
            $scopeHours = $doneHours = 0.0;
            $categories = array_fill_keys(['planned', 'unplanned', 'support'], ['scope' => 0, 'done' => 0]);
            foreach ($this->membersAt($sprint, SprintRules::endOfDay($day)) as $state) {
                if (SprintRules::isRemoved($state['Status'] ?? '')) {
                    continue;
                }
                $category = SprintRules::category($state['Tipo'] ?? '', $state['Planejada'] ?? '');
                $scope++;
                $categories[$category]['scope']++;
                $scopeHours += (float) ($state['EstH'] ?? 0);
                if (SprintRules::isDone($state['Status'] ?? '')) {
                    $done++;
                    $categories[$category]['done']++;
                    $doneHours += (float) ($state['EstH'] ?? 0);
                }
            }
            $points[] = ['date' => $day, 'scope' => $scope, 'done' => $done, 'scopeHours' => round($scopeHours, 2), 'doneHours' => round($doneHours, 2), 'categories' => $categories];
        }

        return $points;
    }

    /**
     * Escopo adicionado e retirado depois da linha de base (fim do último dia útil de planejamento).
     *
     * @return array{baselineDate: ?string, baseline: int, added: list<array<string, mixed>>, removed: list<array<string, mixed>>}
     */
    public function scopeChanges(int $sprint, string $start, string $end, ?string $today = null): array
    {
        $today ??= SprintRules::today();
        $days = SprintRules::workingDays($start, $end);
        if ($days === [] || $days[0] > $today) {
            return ['baselineDate' => null, 'baseline' => 0, 'added' => [], 'removed' => []];
        }
        $baselineDay = $days[min(self::PLANNING_DAYS, count($days)) - 1];
        if ($baselineDay > $today) {
            $baselineDay = $days[0];
        }
        $baselineAt = SprintRules::endOfDay($baselineDay);
        $now = SprintRules::endOfDay(min($end, $today));
        $baseline = array_filter($this->membersAt($sprint, $baselineAt), fn (array $state): bool => ! SprintRules::isRemoved($state['Status'] ?? ''));
        $current = $this->membersAt($sprint, $now);
        $added = $removed = [];
        foreach ($current as $id => $state) {
            if (! isset($baseline[$id]) && ! SprintRules::isRemoved($state['Status'] ?? '')) {
                $added[] = $this->summary($state) + ['changedAt' => $this->enteredAt($id, $sprint, $baselineAt)?->toIso8601String()];
            }
        }
        foreach ($baseline as $id => $state) {
            if (! isset($current[$id])) {
                $removed[] = $this->summary($state) + ['reason' => 'Saiu da sprint'];
            } elseif (SprintRules::isRemoved($current[$id]['Status'] ?? '')) {
                $removed[] = $this->summary($current[$id]) + ['reason' => 'Status '.$current[$id]['Status']];
            }
        }

        return ['baselineDate' => $baselineDay, 'baseline' => count($baseline), 'added' => $added, 'removed' => $removed];
    }

    /**
     * Há quanto tempo cada card aberto da sprint está no status atual (dias úteis completos desde a última mudança).
     *
     * @return list<array<string, mixed>>
     */
    public function statusAges(int $sprint, ?string $today = null): array
    {
        $today ??= SprintRules::today();
        $now = SprintRules::endOfDay($today);
        $ages = [];
        foreach ($this->membersAt($sprint, $now) as $id => $state) {
            if (! SprintRules::isOpen($state['Status'] ?? '')) {
                continue;
            }
            $since = $this->lastChange($id, 'Status', $now) ?? $this->createdAt[$id] ?? null;
            $sinceDate = $since?->setTimezone(SprintRules::TIMEZONE)->toDateString();
            $ages[] = $this->summary($state) + [
                'since' => $sinceDate,
                'workingDays' => $sinceDate === null ? null : max(0, count(SprintRules::workingDays($sinceDate, $today)) - 1),
                'knownSince' => $sinceDate !== null,
                'blocked' => SprintRules::isBlocked($state['Status'] ?? ''),
                'notStarted' => SprintRules::isNotStarted($state['Status'] ?? ''),
            ];
        }
        usort($ages, fn (array $a, array $b): int => ($b['workingDays'] ?? PHP_INT_MAX) <=> ($a['workingDays'] ?? PHP_INT_MAX));

        return $ages;
    }

    /**
     * Cards concluídos (passaram a "Implementado") por dia útil, nos dias úteis anteriores a hoje cobertos pelo histórico.
     *
     * @return list<int>
     */
    public function dailyThroughput(int $workingDays = 60, ?string $today = null): array
    {
        $today ??= SprintRules::today();
        if ($this->historyStart === null) {
            return [];
        }
        $first = $this->historyStart->setTimezone(SprintRules::TIMEZONE)->addDay()->toDateString();
        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();
        $days = array_slice(SprintRules::workingDays($first, $yesterday), -$workingDays);
        $counts = array_fill_keys($days, 0);
        foreach ($this->changes as $fields) {
            foreach ($fields['Status'] ?? [] as $change) {
                $day = $change['at']->setTimezone(SprintRules::TIMEZONE)->toDateString();
                if (isset($counts[$day]) && SprintRules::isDone($change['after']) && ! ($change['hasBefore'] && SprintRules::isDone($change['before']))) {
                    $counts[$day]++;
                }
            }
        }

        return array_values($counts);
    }

    /**
     * Congela as sprints encerradas no estado do último dia: status, horas e escopo voltam ao que eram no fim da sprint.
     * Sprints que terminaram antes do início do histórico ficam com o estado atual.
     *
     * @return list<array<string, mixed>>
     */
    public function frozenRows(?string $today = null): array
    {
        $today ??= SprintRules::today();
        $closed = [];
        foreach ($this->effectiveEnds() as $sprint => $end) {
            if ($end < $today && $this->historyStart !== null && SprintRules::endOfDay($end)->gte($this->historyStart)) {
                $closed[$sprint] = $end;
            }
        }
        $rows = [];
        foreach ($this->snapshot['rows'] as $row) {
            if (! isset($closed[(int) $row['Sprint']]) || ($row['Id'] ?? '') === '') {
                $rows[] = $row;
            }
        }
        foreach ($closed as $sprint => $end) {
            foreach ($this->membersAt($sprint, SprintRules::endOfDay($end)) as $state) {
                $rows[] = [...$state, 'Sprint' => $sprint, 'AsOf' => $end];
            }
        }

        return $rows;
    }

    /**
     * Fim efetivo de cada sprint: o time continua trabalhando nela até a véspera do início da sprint seguinte,
     * mesmo quando o cronograma do Monday termina antes.
     *
     * @return array<int, string>
     */
    public function effectiveEnds(): array
    {
        $sprints = collect($this->snapshot['sprints'] ?? [])->filter(fn (array $dates): bool => ! empty($dates['start']) && ! empty($dates['end']))->sortBy('start')->values();
        $ends = [];
        foreach ($sprints as $index => $dates) {
            $next = $sprints->get($index + 1);
            $dayBeforeNext = $next ? CarbonImmutable::parse($next['start'])->subDay()->toDateString() : $dates['end'];
            $ends[(int) $dates['number']] = max($dates['end'], $dayBeforeNext);
        }

        return $ends;
    }

    /** @return array{id: string, name: string, status: string, owner: string, priority: string, url: string, estimate: ?float, planned: string, type: string} */
    public function summary(array $state): array
    {
        return [
            'id' => (string) ($state['Id'] ?? ''),
            'name' => (string) ($state['Nome'] ?? ''),
            'status' => (string) ($state['Status'] ?? ''),
            'owner' => (string) ($state['Responsavel'] ?? ''),
            'priority' => (string) ($state['Prioridade'] ?? ''),
            'url' => (string) ($state['Url'] ?? ''),
            'estimate' => isset($state['EstH']) ? (float) $state['EstH'] : null,
            'planned' => (string) ($state['Planejada'] ?? ''),
            'type' => (string) ($state['Tipo'] ?? ''),
        ];
    }

    /** O card já tinha sido criado e ainda não tinha sido excluído no momento informado. */
    private function existsAt(string $itemId, CarbonImmutable $at): bool
    {
        return ! (isset($this->createdAt[$itemId]) && $this->createdAt[$itemId]->gt($at))
            && ! (isset($this->deletedAt[$itemId]) && $this->deletedAt[$itemId]->lte($at));
    }

    private function valueAt(string $itemId, string $field, CarbonImmutable $at, mixed $current): mixed
    {
        $changes = $this->changes[$itemId][$field] ?? [];
        $value = null;
        $found = false;
        foreach ($changes as $change) {
            if ($change['at']->gt($at)) {
                return $found ? $value : ($change['hasBefore'] ? $change['before'] : $current);
            }
            $value = $change['after'];
            $found = true;
        }

        return $found ? $value : $current;
    }

    private function lastChange(string $itemId, string $field, CarbonImmutable $at): ?CarbonImmutable
    {
        $last = null;
        foreach ($this->changes[$itemId][$field] ?? [] as $change) {
            if ($change['at']->gt($at)) {
                break;
            }
            $last = $change['at'];
        }

        return $last;
    }

    /** Quando o card passou a fazer parte da sprint depois da linha de base. */
    private function enteredAt(string $itemId, int $sprint, CarbonImmutable $after): ?CarbonImmutable
    {
        foreach ($this->changes[$itemId]['Sprint'] ?? [] as $change) {
            if ($change['at']->gt($after) && in_array($sprint, (array) $change['after'], true)) {
                return $change['at'];
            }
        }
        $created = $this->createdAt[$itemId] ?? null;

        return $created?->gt($after) ? $created : null;
    }

    /** @param array<string, string> $fieldByColumn */
    private function record(MondayActivityEvent $event, array $fieldByColumn): void
    {
        $id = (string) $event->item_id;
        $at = CarbonImmutable::parse($event->occurred_at, 'UTC');
        $this->historyStart ??= $at;
        $this->eventNames[$id] ??= (string) ($event->data['pulse_name'] ?? '');
        if ($event->event === 'create_pulse') {
            $this->createdAt[$id] ??= $at;

            return;
        }
        if ($event->event === 'delete_pulse') {
            $this->deletedAt[$id] = $at;

            return;
        }
        $field = $fieldByColumn[(string) $event->column_id] ?? null;
        if ($field === null || ! in_array($event->event, ['update_column_value', 'batch_change_pulses_column_value'], true)) {
            return;
        }
        $isBatch = $event->event === 'batch_change_pulses_column_value';
        if ($isBatch && $event->value === null) {
            return;
        }
        $this->changes[$id][$field][] = [
            'at' => $at,
            'before' => $this->decode($field, (string) $event->column_id, $event->previous_value),
            'after' => $this->decode($field, (string) $event->column_id, $event->value),
            'hasBefore' => ! $isBatch,
        ];
    }

    private function decode(string $field, string $columnId, mixed $value): mixed
    {
        if ($field === 'Sprint') {
            $numbers = [];
            foreach ((is_array($value) ? $value['linkedPulseIds'] ?? [] : []) as $link) {
                $number = $this->snapshot['sprintItems'][(string) ($link['linkedPulseId'] ?? '')] ?? null;
                if ($number !== null) {
                    $numbers[] = (int) $number;
                }
            }

            return $numbers;
        }
        if (in_array($field, self::NUMBER_FIELDS, true)) {
            return is_array($value) && is_numeric($value['value'] ?? null) ? (float) $value['value'] : null;
        }
        if (! is_array($value)) {
            return '';
        }

        return (string) ($value['label']['text'] ?? (isset($value['index']) ? $this->snapshot['labels'][$columnId][(string) $value['index']] ?? '' : ''));
    }
}
