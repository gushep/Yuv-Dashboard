<?php

namespace App\Services;

use App\Models\MondayConnection;
use App\Support\SprintRules;

/**
 * Torre de controle da sprint: reúne trajetória, sinais de alerta, previsão e manobras sugeridas.
 * Os limites de cada sinal estão nas constantes abaixo para facilitar a calibração.
 */
class ControlTowerService
{
    /** Dias úteis no mesmo status a partir dos quais um card em andamento é considerado parado. */
    public const STALLED_DAYS = 3;

    /** Dias úteis de impedimento a partir dos quais o sinal fica vermelho. */
    public const BLOCKED_RED_DAYS = 2;

    /** Escopo adicionado após a linha de base (% da linha de base): amarelo acima de 10%, vermelho acima de 25%. */
    public const SCOPE_YELLOW = 10;

    public const SCOPE_RED = 25;

    /** Probabilidade de concluir o compromisso: verde a partir de 85%, vermelho abaixo de 50%. */
    public const PROBABILITY_GREEN = 85;

    public const PROBABILITY_RED = 50;

    private const DEFAULT_SUPPORT_RESERVE_MINUTES = 2400;

    public function __construct(private ThroughputForecast $forecast) {}

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
        $base = ['sprint' => $sprint, 'sprints' => $numbers->all(), 'today' => $today, 'syncedAt' => $snapshot['syncedAt'] ?? null, 'historyStart' => $timeline->historyStart()?->toIso8601String()];
        $range = $sprint === null ? null : $dates->get($sprint);
        if ($range === null) {
            return $base + ['available' => false, 'reason' => 'Datas da sprint indisponíveis no Monday: cadastre o cronograma da sprint para acompanhar a trajetória.'];
        }

        $workingDays = SprintRules::workingDays($range['start'], $range['end']);
        $remainingDays = count(array_filter($workingDays, fn (string $day): bool => $day >= $today));
        $members = $timeline->membersAt($sprint, SprintRules::endOfDay(min($range['end'], $today)));
        $items = [];
        foreach ($members as $id => $state) {
            if (SprintRules::isRemoved($state['Status'] ?? '')) {
                continue;
            }
            $items[$id] = $timeline->summary($state) + [
                'done' => SprintRules::isDone($state['Status'] ?? ''),
                'doneNotDelivered' => SprintRules::isDoneNotDelivered($state['Status'] ?? ''),
                'notStarted' => SprintRules::isNotStarted($state['Status'] ?? ''),
                // Compromisso da sprint = card marcado como Planejada no Monday.
                'committed' => SprintRules::normalize($state['Planejada'] ?? '') === 'planejada',
                'type' => (string) ($state['Tipo'] ?? ''),
            ];
        }
        $remaining = array_filter($items, fn (array $item): bool => ! $item['done']);
        $remainingCommitted = array_filter($remaining, fn (array $item): bool => $item['committed']);
        $committed = array_filter($items, fn (array $item): bool => $item['committed']);
        $samples = $timeline->dailyThroughput(60, $today);
        $forecast = $this->forecast->simulate($samples, $remainingDays, count($remaining), count($remainingCommitted), seed: $sprint * 1000 + (int) str_replace('-', '', $today) % 1000);
        $ages = $timeline->statusAges($sprint, $today);
        $stalled = array_values(array_filter($ages, fn (array $age): bool => ! $age['blocked'] && ! $age['notStarted'] && ($age['workingDays'] ?? 0) >= self::STALLED_DAYS));
        $blocked = array_values(array_filter($ages, fn (array $age): bool => $age['blocked']));
        $scope = $timeline->scopeChanges($sprint, $range['start'], $range['end'], $today);
        $support = $this->supportPace($snapshot['rows'], $sprint, $range, $connection, $today);
        $capacity = $this->capacity($members, $sprint, $range, $connection, $today);
        $decision = $this->decisionPoint($workingDays, $today, count($remainingCommitted), $forecast['mean']);
        $maneuvers = $this->maneuvers($remaining, $forecast);

        $signals = [
            $this->deliverySignal($forecast, count($remainingCommitted), $remainingDays),
            $this->scopeSignal($scope),
            $this->stalledSignal($stalled),
            $this->blockedSignal($blocked),
            $this->supportSignal($support),
            $this->capacitySignal($capacity),
        ];
        $levels = array_column($signals, 'level');

        return $base + [
            'available' => true,
            'dates' => ['start' => $range['start'], 'end' => $range['end'], 'name' => $range['name'] ?? "Sprint {$sprint}"],
            'workingDays' => count($workingDays),
            'remainingDays' => $remainingDays,
            'status' => in_array('red', $levels, true) ? 'red' : (in_array('yellow', $levels, true) ? 'yellow' : 'green'),
            'signals' => $signals,
            'totals' => [
                'scope' => count($items), 'done' => count($items) - count($remaining),
                'doneNotDelivered' => count(array_filter($items, fn (array $item): bool => $item['doneNotDelivered'])),
                'remaining' => count($remaining),
                'committed' => count($committed), 'committedDone' => count($committed) - count($remainingCommitted),
            ],
            'burnup' => $timeline->burnup($sprint, $range['start'], $range['end'], $today),
            'scopeChanges' => $scope,
            'stalled' => $stalled,
            'blocked' => $blocked,
            'forecast' => $forecast,
            'support' => $support,
            'capacity' => $capacity,
            'decision' => $decision,
            'maneuvers' => $maneuvers,
            'items' => array_values($items),
            'alerts' => $this->alerts($stalled, $blocked, $support, $signals[0]),
            'thresholds' => ['stalledDays' => self::STALLED_DAYS, 'blockedRedDays' => self::BLOCKED_RED_DAYS, 'scopeYellow' => self::SCOPE_YELLOW, 'scopeRed' => self::SCOPE_RED, 'probabilityGreen' => self::PROBABILITY_GREEN, 'probabilityRed' => self::PROBABILITY_RED],
        ];
    }

    /** @return array{key: string, label: string, level: string, detail: string} */
    private function deliverySignal(array $forecast, int $remainingCommitted, int $remainingDays): array
    {
        $signal = ['key' => 'delivery', 'label' => 'Entrega do compromisso'];
        if ($remainingCommitted === 0) {
            return $signal + ['level' => 'green', 'detail' => 'Todo o compromisso da sprint já foi concluído.'];
        }
        if ($remainingDays === 0) {
            return $signal + ['level' => 'red', 'detail' => "Sprint no fim com {$remainingCommitted} card(s) do compromisso em aberto."];
        }
        if (! $forecast['available']) {
            return $signal + ['level' => 'unknown', 'detail' => 'Histórico de entregas insuficiente para estimar a probabilidade (mínimo de 10 dias úteis).'];
        }
        $probability = $forecast['probabilityCommitted'];
        $level = $probability >= self::PROBABILITY_GREEN ? 'green' : ($probability < self::PROBABILITY_RED ? 'red' : 'yellow');

        return $signal + ['level' => $level, 'detail' => "{$this->percent($probability)} de chance de concluir os {$remainingCommitted} card(s) restantes do compromisso nos {$remainingDays} dia(s) útil(eis) que faltam."];
    }

    private function scopeSignal(array $scope): array
    {
        $signal = ['key' => 'scope', 'label' => 'Escopo adicionado'];
        if ($scope['baselineDate'] === null) {
            return $signal + ['level' => 'unknown', 'detail' => 'A sprint ainda não começou.'];
        }
        // Suporte que chega no meio da sprint é esperado e medido pela reserva de suporte.
        $support = count(array_filter($scope['added'], fn (array $item): bool => SprintRules::normalize($item['type']) === 'suporte'));
        $added = count($scope['added']) - $support;
        $share = $scope['baseline'] ? $added / $scope['baseline'] * 100 : ($added ? 100 : 0);
        $level = $share > self::SCOPE_RED ? 'red' : ($share > self::SCOPE_YELLOW ? 'yellow' : 'green');

        return $signal + ['level' => $level, 'detail' => "{$added} card(s) fora do suporte entraram depois do planejamento ({$this->percent($share)} da linha de base de {$scope['baseline']} em ".$this->day($scope['baselineDate'])."); mais {$support} de suporte. ".count($scope['removed']).' saíram ou foram retirados.'];
    }

    private function stalledSignal(array $stalled): array
    {
        $count = count($stalled);
        $level = $count === 0 ? 'green' : ($count >= 3 ? 'red' : 'yellow');

        return ['key' => 'stalled', 'label' => 'Itens parados', 'level' => $level,
            'detail' => $count ? "{$count} card(s) em andamento sem mudar de status há ".self::STALLED_DAYS.' dias úteis ou mais.' : 'Nenhum card em andamento parado há '.self::STALLED_DAYS.' dias úteis ou mais.'];
    }

    private function blockedSignal(array $blocked): array
    {
        $old = array_filter($blocked, fn (array $age): bool => ($age['workingDays'] ?? PHP_INT_MAX) >= self::BLOCKED_RED_DAYS);
        $level = $blocked === [] ? 'green' : ($old ? 'red' : 'yellow');

        return ['key' => 'blocked', 'label' => 'Impedimentos', 'level' => $level,
            'detail' => $blocked === [] ? 'Nenhum card impedido.' : count($blocked).' card(s) impedido(s); '.count($old).' há '.self::BLOCKED_RED_DAYS.' dias úteis ou mais.'];
    }

    private function supportSignal(array $support): array
    {
        $signal = ['key' => 'support', 'label' => 'Reserva de suporte'];
        if ($support['status'] !== 'ongoing') {
            return $signal + ['level' => $support['status'] === 'finished' && $support['consumed'] > $support['reserve'] ? 'red' : 'green', 'detail' => $support['text']];
        }
        $level = $support['exhaustionDate'] !== null && $support['exhaustionDate'] < $support['sprintEnd'] ? 'red' : (($support['projectedPercent'] ?? 0) > 90 ? 'yellow' : 'green');

        return $signal + ['level' => $level, 'detail' => $support['text']];
    }

    private function capacitySignal(array $capacity): array
    {
        $signal = ['key' => 'capacity', 'label' => 'Capacidade restante'];
        if ($capacity['ratio'] === null) {
            return $signal + ['level' => 'unknown', 'detail' => $capacity['text']];
        }
        $level = $capacity['ratio'] > 100 ? 'red' : ($capacity['ratio'] >= 90 ? 'yellow' : 'green');

        return $signal + ['level' => $level, 'detail' => $capacity['text']];
    }

    /**
     * Ritmo da reserva de suporte: horas de suporte por dia útil projetadas até o fim da sprint.
     *
     * @return array<string, mixed>
     */
    private function supportPace(array $rows, int $sprint, array $range, MondayConnection $connection, string $today): array
    {
        $consumed = 0.0;
        $seen = [];
        foreach ($rows as $row) {
            if ((int) $row['Sprint'] === $sprint && SprintRules::normalize($row['Tipo'] ?? '') === 'suporte' && ! isset($seen[$row['Id'] ?? ''])) {
                $seen[$row['Id'] ?? ''] = true;
                $consumed += (float) ($row['RealH'] ?? 0) * 60;
            }
        }
        $reserve = (int) ($connection->forecast_settings['supportReserves'][(string) $sprint] ?? self::DEFAULT_SUPPORT_RESERVE_MINUTES);
        $days = SprintRules::workingDays($range['start'], $range['end']);
        $elapsed = count(array_filter($days, fn (string $day): bool => $day <= $today));
        $result = ['consumed' => (int) round($consumed), 'reserve' => $reserve, 'sprintEnd' => end($days) ?: null, 'exhaustionDate' => null, 'projected' => null, 'projectedPercent' => null];
        if ($days === [] || $elapsed === 0) {
            return $result + ['status' => 'not-started', 'text' => 'A sprint ainda não começou.'];
        }
        $daily = $consumed / $elapsed;
        $projected = (int) round($daily * count($days));
        $index = $reserve > 0 && $daily > 0 ? (int) ceil($reserve / $daily) : null;
        $result = ['projected' => $projected, 'projectedPercent' => $reserve > 0 ? round($projected / $reserve * 100, 1) : null,
            'exhaustionDate' => $index !== null && $index <= count($days) ? $days[$index - 1] : null] + $result;
        if ($elapsed >= count($days)) {
            return $result + ['status' => 'finished', 'text' => "Sprint encerrada: {$this->hours($consumed)} de suporte para {$this->hours($reserve)} reservadas."];
        }
        $ending = $result['exhaustionDate'] === null ? 'a reserva é suficiente até o fim da sprint'
            : ($result['exhaustionDate'] < $result['sprintEnd'] ? 'a reserva acaba em '.$this->day($result['exhaustionDate']).', antes do fim da sprint' : 'a reserva acaba no último dia da sprint');

        return $result + ['status' => 'ongoing', 'text' => "{$this->hours($consumed)} de suporte em {$elapsed} dia(s) útil(eis); no ritmo atual, {$this->hours($projected)} até o fim ({$this->percent($result['projectedPercent'])} da reserva) e {$ending}."];
    }

    /**
     * Esforço restante dos cards abertos contra a capacidade futura das pessoas da sprint.
     *
     * @param  array<string, array<string, mixed>>  $members
     * @return array{remainingHours: float, availableHours: ?float, ratio: ?float, missing: int, people: int, text: string}
     */
    private function capacity(array $members, int $sprint, array $range, MondayConnection $connection, string $today): array
    {
        $settings = $connection->metricSettings();
        $excluded = array_map([SprintRules::class, 'normalize'], $settings['excludedPeople']);
        $people = [];
        $remaining = 0.0;
        $missing = 0;
        foreach ($members as $state) {
            foreach (array_filter(array_map('trim', explode(',', (string) ($state['Responsavel'] ?? '')))) as $person) {
                if (! in_array(SprintRules::normalize($person), $excluded, true)) {
                    $people[$person] = true;
                }
            }
            if (! SprintRules::isOpen($state['Status'] ?? '')) {
                continue;
            }
            $estimate = $state['EstH'] ?? null;
            $real = $state['RealH'] ?? null;
            $revised = $state['RemainingH'] ?? null;
            $effort = $revised !== null ? (float) $revised
                : ($estimate === null || $estimate <= 0 ? null : (SprintRules::isNotStarted($state['Status'] ?? '') ? (float) $estimate : max(0.0, (float) $estimate - (float) ($real ?? 0))));
            $effort === null ? $missing++ : $remaining += $effort;
        }
        $days = SprintRules::workingDays($range['start'], $range['end']);
        $future = count(array_filter($days, fn (string $day): bool => $day > $today));
        $overrides = $connection->capacity_overrides ?? [];
        $total = 0.0;
        foreach (array_keys($people) as $person) {
            $total += isset($overrides["{$sprint}|{$person}"]) ? $overrides["{$sprint}|{$person}"] / 60 : (float) $settings['capacityHours'];
        }
        $available = $days === [] ? null : round($total * $future / count($days), 1);
        $ratio = $available ? round($remaining / $available * 100, 1) : null;
        $text = $available === null ? 'Datas da sprint indisponíveis.'
            : ($available == 0 ? 'Sem dias úteis futuros na sprint.'
            : "{$this->hours($remaining * 60)} de esforço restante para {$this->hours($available * 60)} de capacidade futura ({$this->percent($ratio)}).".($missing ? " {$missing} card(s) sem estimativa ficaram fora da conta." : ''));

        return ['remainingHours' => round($remaining, 1), 'availableHours' => $available, 'ratio' => $ratio, 'missing' => $missing, 'people' => count($people), 'text' => $text];
    }

    /**
     * Última data para decidir cortes: depois dela, no ritmo médio do time, o compromisso restante não cabe nos dias que faltam.
     *
     * @param  list<string>  $workingDays
     * @return array{date: ?string, daysNeeded: ?int, late: bool, text: string}
     */
    private function decisionPoint(array $workingDays, string $today, int $remainingCommitted, ?float $mean): array
    {
        if ($remainingCommitted === 0) {
            return ['date' => null, 'daysNeeded' => 0, 'late' => false, 'text' => 'Sem decisão pendente: o compromisso está concluído.'];
        }
        if (! $mean) {
            return ['date' => null, 'daysNeeded' => null, 'late' => false, 'text' => 'Sem histórico de entregas suficiente para calcular o ponto de decisão.'];
        }
        $needed = (int) ceil($remainingCommitted / $mean);
        $index = count($workingDays) - $needed - 1;
        $todayIndex = count(array_filter($workingDays, fn (string $day): bool => $day < $today));
        if ($index < $todayIndex) {
            return ['date' => null, 'daysNeeded' => $needed, 'late' => true,
                'text' => "Decidir agora: no ritmo médio de {$this->number($mean)} card(s) por dia útil, o compromisso restante precisa de {$needed} dia(s) útil(eis) e não cabe no tempo que falta sem retirar escopo."];
        }

        return ['date' => $workingDays[$index], 'daysNeeded' => $needed, 'late' => false,
            'text' => 'Ponto de decisão em '.$this->day($workingDays[$index]).": no ritmo médio de {$this->number($mean)} card(s) por dia útil, o compromisso restante precisa de {$needed} dia(s) útil(eis). Depois dessa data, retirar escopo deixa de evitar o atraso."];
    }

    /**
     * Cards sugeridos para sair da sprint quando a previsão com 85% de confiança não cobre o que falta:
     * primeiro os não iniciados fora do compromisso, da menor para a maior prioridade.
     *
     * @param  array<string, array<string, mixed>>  $remaining
     * @return array{shortfall: int, suggestions: list<array<string, mixed>>, text: string}
     */
    private function maneuvers(array $remaining, array $forecast): array
    {
        if (! $forecast['available']) {
            return ['shortfall' => 0, 'suggestions' => [], 'text' => 'Sem previsão disponível para sugerir manobras.'];
        }
        $shortfall = max(0, count($remaining) - $forecast['p85']);
        if ($shortfall === 0) {
            return ['shortfall' => 0, 'suggestions' => [], 'text' => 'Com 85% de confiança, o time conclui todo o escopo restante. Nenhuma manobra necessária.'];
        }
        $candidates = array_values(array_filter($remaining, fn (array $item): bool => ! $item['doneNotDelivered']));
        usort($candidates, fn (array $a, array $b): int => [$a['committed'], ! $a['notStarted'], -SprintRules::priorityRank($a['priority'])] <=> [$b['committed'], ! $b['notStarted'], -SprintRules::priorityRank($b['priority'])]);
        $suggestions = array_slice($candidates, 0, $shortfall);

        return ['shortfall' => $shortfall, 'suggestions' => $suggestions,
            'text' => "Com 85% de confiança, o time conclui {$forecast['p85']} dos ".count($remaining)." card(s) restantes. Para fechar a diferença de {$shortfall}, considere retirar os cards abaixo (primeiro os não iniciados fora do compromisso e de menor prioridade)."];
    }

    /**
     * Alertas com chave estável, para o painel avisar apenas o que é novo.
     *
     * @return list<array{key: string, level: string, text: string}>
     */
    private function alerts(array $stalled, array $blocked, array $support, array $delivery): array
    {
        $alerts = [];
        foreach ($blocked as $item) {
            $alerts[] = ['key' => "blocked:{$item['id']}:{$item['since']}", 'level' => ($item['workingDays'] ?? PHP_INT_MAX) >= self::BLOCKED_RED_DAYS ? 'red' : 'yellow',
                'text' => "Impedido ({$item['status']}) ".$this->age($item).": {$item['name']}"];
        }
        foreach ($stalled as $item) {
            $alerts[] = ['key' => "stalled:{$item['id']}:{$item['since']}", 'level' => 'yellow', 'text' => "Parado em {$item['status']} ".$this->age($item).": {$item['name']}"];
        }
        if (($support['exhaustionDate'] ?? null) !== null && $support['exhaustionDate'] < ($support['sprintEnd'] ?? '')) {
            $alerts[] = ['key' => "support:{$support['exhaustionDate']}", 'level' => 'red', 'text' => 'Reserva de suporte deve acabar em '.$this->day($support['exhaustionDate']).', antes do fim da sprint.'];
        }
        if ($delivery['level'] === 'red') {
            $alerts[] = ['key' => 'delivery:red', 'level' => 'red', 'text' => $delivery['detail']];
        }

        return $alerts;
    }

    private function age(array $item): string
    {
        return $item['knownSince'] ? "há {$item['workingDays']} dia(s) útil(eis)" : 'desde antes do início do histórico';
    }

    private function hours(float|int|null $minutes): string
    {
        $minutes = (int) round((float) $minutes);

        return intdiv($minutes, 60).'h'.($minutes % 60 ? str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) : '');
    }

    private function percent(?float $value): string
    {
        return $value === null ? '—' : str_replace('.', ',', (string) round($value, 1)).'%';
    }

    private function number(float $value): string
    {
        return str_replace('.', ',', (string) round($value, 1));
    }

    private function day(string $date): string
    {
        return implode('/', array_reverse(array_slice(explode('-', $date), 1)));
    }
}
