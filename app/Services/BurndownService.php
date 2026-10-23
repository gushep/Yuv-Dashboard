<?php

namespace App\Services;

use App\Models\MondayConnection;
use App\Support\SprintRules;
use Carbon\CarbonImmutable;

/**
 * Burndown da sprint: quanto falta implementar no fim de cada dia útil, contra a linha ideal,
 * no geral, só no suporte e no restante (planejado e não planejado). Também calcula o SLA do suporte.
 * Usa o mesmo histórico reconstituído da torre de controle, em cards e em horas estimadas.
 */
class BurndownService
{
    /** Painéis do burndown: origens do card somadas em cada um (null = todas). */
    private const PANELS = [
        'all' => ['label' => 'Geral', 'categories' => null],
        'support' => ['label' => 'Suporte', 'categories' => ['support']],
        'other' => ['label' => 'Planejado e não planejado', 'categories' => ['planned', 'unplanned']],
    ];

    /**
     * @param  array<string, mixed>  $snapshot  estado atual dos cards (sem congelamento)
     * @return array<string, mixed>
     */
    public function build(array $snapshot, MondayConnection $connection, ?int $sprint = null, ?string $today = null): array
    {
        $today ??= SprintRules::today();
        $timeline = SprintTimeline::fromDatabase($snapshot, $connection);
        $dates = collect($snapshot['sprints'] ?? [])->keyBy(fn (array $item): int => (int) $item['number']);
        $numbers = collect($snapshot['rows'])->pluck('Sprint')->merge($dates->keys())->map(fn (mixed $value): int => (int) $value)->unique()->sortDesc()->values();
        $sprint ??= $dates->first(fn (array $item): bool => $item['start'] <= $today && $item['end'] >= $today)['number'] ?? $numbers->first();
        $historyStart = $timeline->historyStart();
        $base = ['sprint' => $sprint, 'sprints' => $numbers->all(), 'today' => $today, 'syncedAt' => $snapshot['syncedAt'] ?? null, 'historyStart' => $historyStart?->toIso8601String()];
        $range = $sprint === null ? null : $dates->get($sprint);
        if ($range === null) {
            return $base + ['available' => false, 'reason' => 'Datas da sprint indisponíveis no Monday: cadastre o cronograma da sprint para acompanhar o burndown.'];
        }

        $workingDays = SprintRules::workingDays($range['start'], $range['end']);
        $remainingDays = count(array_filter($workingDays, fn (string $day): bool => $day >= $today));
        $burnup = $timeline->burnup($sprint, $range['start'], $range['end'], $today);
        $panels = [];
        foreach (self::PANELS as $key => $panel) {
            $points = $this->points($this->slice($burnup, $panel['categories']), $workingDays);
            $panels[] = ['key' => $key, 'label' => $panel['label'], 'points' => $points, 'ideal' => $this->ideal($points, $workingDays), 'summary' => $this->summary($points, $remainingDays)];
        }

        return $base + [
            'available' => true,
            'dates' => ['start' => $range['start'], 'end' => $range['end'], 'name' => $range['name'] ?? "Sprint {$sprint}"],
            'days' => $workingDays,
            'workingDays' => count($workingDays),
            'remainingDays' => $remainingDays,
            'panels' => $panels,
            'sla' => $this->supportSla($timeline, $sprint, SprintRules::endOfDay(min($range['end'], $today))),
            // Antes do primeiro evento copiado não há como reconstituir os dias: valem os status atuais.
            'partialHistory' => $historyStart === null || $historyStart->gt(SprintRules::endOfDay($range['start'])),
        ];
    }

    /**
     * Totais de cada dia somando só as origens do painel.
     *
     * @param  list<array<string, mixed>>  $burnup
     * @param  list<string>|null  $categories
     * @return list<array{date: string, scope: int, done: int, scopeHours: float, doneHours: float}>
     */
    private function slice(array $burnup, ?array $categories): array
    {
        return array_map(function (array $point) use ($categories): array {
            if ($categories === null) {
                return ['date' => $point['date'], 'scope' => $point['scope'], 'done' => $point['done'], 'scopeHours' => $point['scopeHours'], 'doneHours' => $point['doneHours']];
            }
            $totals = ['date' => $point['date'], 'scope' => 0, 'done' => 0, 'scopeHours' => 0.0, 'doneHours' => 0.0];
            foreach ($categories as $category) {
                foreach (['scope', 'done', 'scopeHours', 'doneHours'] as $field) {
                    $totals[$field] += $point['categories'][$category][$field];
                }
            }

            return [...$totals, 'scopeHours' => round($totals['scopeHours'], 2), 'doneHours' => round($totals['doneHours'], 2)];
        }, $burnup);
    }

    /**
     * Restante e variação de escopo de cada dia útil já decorrido.
     *
     * @param  list<array{date: string, scope: int, done: int, scopeHours: float, doneHours: float}>  $burnup
     * @param  list<string>  $workingDays
     * @return list<array{date: string, scope: int, done: int, remaining: int, scopeChange: int, scopeHours: float, doneHours: float, remainingHours: float}>
     */
    private function points(array $burnup, array $workingDays): array
    {
        $points = [];
        $previous = null;
        foreach ($burnup as $point) {
            if (! in_array($point['date'], $workingDays, true)) {
                continue;
            }
            $points[] = [
                'date' => $point['date'],
                'scope' => $point['scope'],
                'done' => $point['done'],
                'remaining' => $point['scope'] - $point['done'],
                'scopeChange' => $previous === null ? 0 : $point['scope'] - $previous['scope'],
                'scopeHours' => $point['scopeHours'],
                'doneHours' => $point['doneHours'],
                'remainingHours' => round($point['scopeHours'] - $point['doneHours'], 2),
            ];
            $previous = $point;
        }

        return $points;
    }

    /**
     * Linha ideal: parte do escopo do fim do primeiro dia útil e cai em linha reta até zero no último dia útil.
     *
     * @param  list<array<string, mixed>>  $points
     * @param  list<string>  $workingDays
     * @return array{start: int, startHours: float, cards: list<float>, hours: list<float>}
     */
    private function ideal(array $points, array $workingDays): array
    {
        $start = $points[0]['scope'] ?? 0;
        $startHours = $points[0]['scopeHours'] ?? 0.0;
        $steps = max(1, count($workingDays) - 1);
        $line = fn (float $from): array => array_map(fn (int $index): float => round($from * (1 - $index / $steps), 2), array_keys($workingDays));

        return ['start' => $start, 'startHours' => $startHours, 'cards' => $line($start), 'hours' => $line($startHours)];
    }

    /**
     * Números do último dia: restante, ritmo médio até aqui e ritmo necessário até o fim.
     *
     * @param  list<array<string, mixed>>  $points
     * @return array<string, mixed>|null
     */
    private function summary(array $points, int $remainingDays): ?array
    {
        if ($points === []) {
            return null;
        }
        $first = $points[0];
        $last = $points[count($points) - 1];
        $elapsed = count($points);
        $pace = fn (float $value, int $days): ?float => $days > 0 ? round($value / $days, 1) : null;

        return [
            'date' => $last['date'],
            'initialScope' => $first['scope'], 'scope' => $last['scope'], 'done' => $last['done'], 'remaining' => $last['remaining'],
            'initialScopeHours' => $first['scopeHours'], 'scopeHours' => $last['scopeHours'], 'doneHours' => $last['doneHours'], 'remainingHours' => $last['remainingHours'],
            'averagePace' => $pace($last['done'], $elapsed), 'averagePaceHours' => $pace($last['doneHours'], $elapsed),
            'requiredPace' => $pace($last['remaining'], $remainingDays), 'requiredPaceHours' => $pace($last['remainingHours'], $remainingDays),
        ];
    }

    /**
     * SLA do suporte: tempo corrido da abertura do card no Monday até o status Implementado.
     * Cards em aberto mostram há quanto tempo estão abertos; sem a criação no log copiado, o tempo fica desconhecido.
     *
     * @return array{items: list<array<string, mixed>>, done: int, averageHours: ?float, medianHours: ?float, maxHours: ?float, open: int, openAverageHours: ?float, unknown: int}
     */
    private function supportSla(SprintTimeline $timeline, int $sprint, CarbonImmutable $at): array
    {
        $now = CarbonImmutable::now()->min($at);
        $items = [];
        foreach ($timeline->membersAt($sprint, $at) as $id => $state) {
            $status = (string) ($state['Status'] ?? '');
            if (SprintRules::isRemoved($status) || SprintRules::category($state['Tipo'] ?? '', $state['Planejada'] ?? '') !== 'support') {
                continue;
            }
            $createdAt = $timeline->createdAt((string) $id);
            $done = SprintRules::isDone($status);
            $doneAt = $done ? $timeline->enteredStatusAt((string) $id, $status, $at) : null;
            $until = $done ? $doneAt : $now;
            $items[] = $timeline->summary($state) + [
                'done' => $done,
                'createdAt' => $createdAt?->toIso8601String(),
                'doneAt' => $doneAt?->toIso8601String(),
                'hours' => $createdAt === null || $until === null ? null : round(max(0, $createdAt->diffInMinutes($until)) / 60, 1),
            ];
        }
        $closed = array_values(array_filter(array_map(fn (array $item): ?float => $item['done'] ? $item['hours'] : null, $items), fn (?float $hours): bool => $hours !== null));
        $open = array_values(array_filter(array_map(fn (array $item): ?float => $item['done'] ? null : $item['hours'], $items), fn (?float $hours): bool => $hours !== null));
        sort($closed);

        return [
            'items' => $items,
            'done' => count($closed),
            'averageHours' => $closed === [] ? null : round(array_sum($closed) / count($closed), 1),
            'medianHours' => $closed === [] ? null : $this->median($closed),
            'maxHours' => $closed === [] ? null : max($closed),
            'open' => count(array_filter($items, fn (array $item): bool => ! $item['done'])),
            'openAverageHours' => $open === [] ? null : round(array_sum($open) / count($open), 1),
            'unknown' => count(array_filter($items, fn (array $item): bool => $item['hours'] === null)),
        ];
    }

    /** @param  list<float>  $sorted */
    private function median(array $sorted): float
    {
        $middle = intdiv(count($sorted), 2);

        return round(count($sorted) % 2 ? $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2, 1);
    }
}
