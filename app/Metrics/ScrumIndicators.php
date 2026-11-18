<?php

namespace App\Metrics;

use App\Support\SprintRules;

/**
 * Indicadores Scrum (espelho de public/scrum-indicators.js): antes e depois das mudanças, em dois períodos de sprints.
 * Cada indicador é a média por sprint do período ou um percentual sobre os cards do período, sem os retirados do
 * escopo. Os selos (×N, p.p., %, N× menor) e os formatos seguem o infográfico da tela.
 */
final class ScrumIndicators
{
    /** Padrão: as 3 últimas sprints encerradas são o "após"; todas as anteriores, o "antes". */
    public const DEFAULT_AFTER = 3;

    /**
     * As 8 frentes: indicador principal com o selo e um segundo indicador.
     *
     * @var list<array{title: string, unit: string, main: array{key: string, label: string, kind: string, better: string, format: string}, sub: array{key: string, label: string, format: string}}>
     */
    public const FRONTS = [
        ['title' => 'Volume de entrega', 'unit' => 'cards / sprint', 'main' => ['key' => 'donePerSprint', 'label' => 'cards entregues por sprint', 'kind' => 'ratio', 'better' => 'higher', 'format' => 'count'], 'sub' => ['key' => 'doneHoursPerSprint', 'label' => 'Horas entregues por sprint', 'format' => 'hours']],
        ['title' => 'Compromisso cumprido', 'unit' => 'do planejado', 'main' => ['key' => 'plannedDelivered', 'label' => 'planejados entregues', 'kind' => 'pp', 'better' => 'higher', 'format' => 'percent'], 'sub' => ['key' => 'plannedPerSprint', 'label' => 'Cards planejados por sprint', 'format' => 'count']],
        ['title' => 'Estimativa precisa', 'unit' => 'de desvio', 'main' => ['key' => 'deviation', 'label' => 'desvio real × estimado', 'kind' => 'deviation', 'better' => 'lower', 'format' => 'signed'], 'sub' => ['key' => 'withEstimate', 'label' => 'Cards com estimativa', 'format' => 'percentFine']],
        ['title' => 'Backlog refinado', 'unit' => 'cards prontos', 'main' => ['key' => 'ready', 'label' => 'cards prontos', 'kind' => 'pp', 'better' => 'higher', 'format' => 'percent'], 'sub' => ['key' => 'origin', 'label' => 'Com origem definida', 'format' => 'percentFine']],
        ['title' => 'Sustentação sob controle', 'unit' => 'tickets / sprint', 'main' => ['key' => 'supportPerSprint', 'label' => 'tickets registrados por sprint', 'kind' => 'ratio', 'better' => 'higher', 'format' => 'count'], 'sub' => ['key' => 'supportWithHours', 'label' => 'Tickets com horas', 'format' => 'percentFine']],
        ['title' => 'Capacity medida', 'unit' => 'entregas c/ horas', 'main' => ['key' => 'doneWithHours', 'label' => 'entregas com horas apontadas', 'kind' => 'pp', 'better' => 'higher', 'format' => 'percent'], 'sub' => ['key' => 'hoursPerSprint', 'label' => 'Horas por sprint', 'format' => 'hours']],
        ['title' => 'Qualidade', 'unit' => 'de retrabalho', 'main' => ['key' => 'rework', 'label' => 'cards com retrabalho', 'kind' => 'relative', 'better' => 'lower', 'format' => 'percentFine'], 'sub' => ['key' => 'dragged', 'label' => 'Cards arrastados', 'format' => 'percentFine']],
        ['title' => 'Gestão metrificada', 'unit' => 'cards com cliente', 'main' => ['key' => 'client', 'label' => 'cards com cliente identificado', 'kind' => 'pp', 'better' => 'higher', 'format' => 'percent'], 'sub' => ['key' => 'withEstimate', 'label' => 'Cards com estimativa', 'format' => 'percentFine']],
    ];

    /**
     * Os 4 indicadores do topo; a produtividade tem meta de 90%.
     *
     * @var list<array{key: string, title: string, kind: string, better: string, format: string, target?: int}>
     */
    public const HERO = [
        ['key' => 'productivity', 'title' => 'Produtividade', 'kind' => 'pp', 'better' => 'higher', 'format' => 'percent', 'target' => 90],
        ['key' => 'plannedDelivered', 'title' => 'Planejado cumprido', 'kind' => 'pp', 'better' => 'higher', 'format' => 'percent'],
        ['key' => 'rework', 'title' => 'Retrabalho', 'kind' => 'relative', 'better' => 'lower', 'format' => 'percentFine'],
        ['key' => 'deviation', 'title' => 'Desvio da estimativa', 'kind' => 'deviation', 'better' => 'lower', 'format' => 'signed'],
    ];

    private readonly SprintKpis $kpis;

    public function __construct(private readonly MetricsData $data)
    {
        $this->kpis = new SprintKpis($data);
    }

    /**
     * Sprints com datas que já começaram, em ordem crescente.
     *
     * @return list<int>
     */
    public function startedSprints(): array
    {
        $today = $this->data->today();

        return array_values(array_filter($this->data->sprints, fn (int $sprint): bool => ($start = $this->data->datesOf($sprint)['start'] ?? null) && $start <= $today));
    }

    public function closed(int $sprint): bool
    {
        return ($this->data->datesOf($sprint)['end'] ?? '9999') < $this->data->today();
    }

    /**
     * Períodos padrão: as 3 últimas sprints encerradas são o "após" e as anteriores, o "antes".
     *
     * @return array{beforeFrom: int, beforeTo: int, afterFrom: int, afterTo: int}|null
     */
    public function defaults(): ?array
    {
        $done = array_values(array_filter($this->startedSprints(), $this->closed(...)));
        $after = array_slice($done, -self::DEFAULT_AFTER);
        $before = array_slice($done, 0, max(0, count($done) - self::DEFAULT_AFTER));
        if ($after === []) {
            return null;
        }

        return ['beforeFrom' => $before[0] ?? $after[0], 'beforeTo' => $before === [] ? $after[0] : end($before), 'afterFrom' => $after[0], 'afterTo' => end($after)];
    }

    /**
     * Sprints iniciadas entre as duas informadas (em qualquer ordem).
     *
     * @return list<int>
     */
    public function between(int $from, int $to): array
    {
        return array_values(array_filter($this->startedSprints(), fn (int $sprint): bool => $sprint >= min($from, $to) && $sprint <= max($from, $to)));
    }

    /**
     * Indicadores de um período: médias por sprint e percentuais sobre os cards do período.
     *
     * @param  list<int>  $sprints
     * @return array<string, float|int|null>
     */
    public function measure(array $sprints): array
    {
        $rows = array_values(array_filter($this->data->rows, fn (array $row): bool => in_array((int) $row['Sprint'], $sprints, true) && ! SprintRules::isRemoved($row['Status'] ?? null)));
        $count = max(1, count($sprints));
        $isDone = fn (array $row): bool => SprintRules::isDone($row['Status'] ?? null);
        $isSupport = fn (array $row): bool => SprintRules::normalize($row['Tipo'] ?? null) === 'suporte';
        $only = fn (array $list, callable $keep): array => array_values(array_filter($list, $keep));
        $sum = fn (array $list, string $field): float => array_sum(array_map(fn (array $row): float => self::number($row[$field] ?? null), $list));
        $done = $only($rows, $isDone);
        $support = $only($rows, $isSupport);
        $planned = $only($rows, fn (array $row): bool => ! $isSupport($row) && SprintRules::normalize($row['Planejada'] ?? null) === 'planejada');
        $pairs = $only($done, fn (array $row): bool => self::number($row['EstH'] ?? null) > 0 && self::number($row['RealH'] ?? null) > 0);
        $estimate = $sum($pairs, 'EstH');
        $real = $sum($pairs, 'RealH');
        $total = count($rows);

        return [
            'sprints' => count($sprints),
            'cards' => $total,
            'donePerSprint' => count($done) / $count,
            'cardsPerSprint' => $total / $count,
            'nonSupportPerSprint' => ($total - count($support)) / $count,
            'productivity' => self::pct(count($done), $total),
            'plannedDelivered' => self::pct(count($only($planned, $isDone)), count($planned)),
            'plannedPerSprint' => count($planned) / $count,
            'deviation' => $estimate > 0 ? ($real / $estimate - 1) * 100 : null,
            'withEstimate' => self::pct(count($only($rows, fn (array $row): bool => self::number($row['EstH'] ?? null) > 0)), $total),
            'ready' => self::pct(count($only($rows, fn (array $row): bool => self::number($row['EstH'] ?? null) > 0 && self::filled($row['Tipo'] ?? null)
                && self::filled($row['Planejada'] ?? null) && self::filled($row['Prioridade'] ?? null) && self::filled($row['Responsavel'] ?? null))), $total),
            'origin' => self::pct(count($only($rows, fn (array $row): bool => self::filled($row['Planejada'] ?? null))), $total),
            'supportPerSprint' => count($support) / $count,
            'supportWithHours' => self::pct(count($only($support, fn (array $row): bool => self::number($row['RealH'] ?? null) > 0)), count($support)),
            'doneWithHours' => self::pct(count($only($done, fn (array $row): bool => self::number($row['RealH'] ?? null) > 0)), count($done)),
            'hoursPerSprint' => $sum($rows, 'RealH') / $count,
            'doneHoursPerSprint' => $sum($done, 'RealH') / $count,
            'rework' => self::pct(count($only($rows, fn (array $row): bool => SprintKpis::truthy($row['Retrabalho'] ?? null) && (string) $row['Retrabalho'] !== '0')), $total),
            'dragged' => self::pct(count($only($rows, $this->kpis->isArrastado(...))), $total),
            'client' => self::pct(count($only($rows, fn (array $row): bool => self::filled($row['Cliente'] ?? null))), $total),
        ];
    }

    /**
     * Selo da variação, verde (good) quando melhorou: ×N para volumes, p.p. para percentuais, % para taxas que devem
     * cair e N× menor para o desvio da estimativa (comparado em valor absoluto).
     *
     * @param  'ratio'|'pp'|'relative'|'deviation'  $kind
     * @param  'higher'|'lower'  $better
     * @return array{text: string, good: ?bool}
     */
    public static function change(string $kind, float|int|null $before, float|int|null $after, string $better): array
    {
        if ($before === null || $after === null) {
            return ['text' => 'sem base', 'good' => null];
        }
        $improved = $better === 'higher' ? $after > $before : ($kind === 'deviation' ? abs($after) < abs($before) : $after < $before);
        $same = abs($after - $before) < 0.05;
        $text = match ($kind) {
            'ratio' => $before > 0 && $after >= $before ? '×'.self::decimal($after / $before, 1) : ($before > 0 ? '−'.SprintKpis::round((1 - $after / $before) * 100).'%' : 'novo'),
            'pp' => ($after >= $before ? '+' : '−').self::decimal(abs($after - $before), abs($after - $before) >= 10 ? 0 : 1).' p.p.',
            'relative' => $before > 0 ? ($after <= $before ? '−' : '+').SprintKpis::round(abs($after - $before) / $before * 100).'%' : 'novo',
            default => abs($after) > 0 && abs($after) < abs($before)
                ? self::decimal(abs($before) / abs($after), 0).'× menor'
                : (abs($after) >= abs($before) ? '+' : '−').self::decimal(abs(abs($after) - abs($before)), 1).' p.p.',
        };

        return ['text' => $same ? 'estável' : $text, 'good' => $same ? null : $improved];
    }

    /**
     * Números e textos do infográfico para os dois períodos (sem desenho): manchete, indicadores do topo, as 8 frentes,
     * a nota de volume sem suporte e os pontos dos gráficos por sprint.
     *
     * @param  list<int>  $before
     * @param  list<int>  $after
     * @return array<string, mixed>
     */
    public function compare(array $before, array $after): array
    {
        $b = $this->measure($before);
        $a = $this->measure($after);
        $delivery = self::change('ratio', $b['donePerSprint'], $a['donePerSprint'], 'higher');
        $growth = $b['donePerSprint'] > 0 ? ($a['donePerSprint'] / $b['donePerSprint'] - 1) * 100 : null;
        $verb = match (true) {
            $growth === null => 'O time em números',
            $growth >= 5 => 'O time entrega '.str_replace('×', '', $delivery['text']).'× mais por sprint',
            $growth <= -5 => 'O time entrega '.SprintKpis::round(abs($growth)).'% menos por sprint',
            default => 'O time mantém o volume de entregas por sprint',
        };
        $control = $a['rework'] !== null && $b['rework'] !== null && $a['rework'] < $b['rework'] ? ', com mais controle e menos retrabalho' : '';
        $note = $a['supportPerSprint'] > $b['supportPerSprint'] && $b['nonSupportPerSprint'] > 0
            ? 'Nota: parte da variação de volume vem da sustentação registrada na sprint. Sem os tickets de suporte, a média vai de '
                .self::decimal($b['nonSupportPerSprint'], 0).' para '.self::decimal($a['nonSupportPerSprint'], 0).' cards por sprint ('
                .self::change('relative', $b['nonSupportPerSprint'], $a['nonSupportPerSprint'], 'higher')['text'].').'
            : null;
        $all = array_values(array_unique([...$before, ...$after]));

        return [
            'before' => ['sprints' => $before, 'label' => self::label($before), 'measure' => $b],
            'after' => ['sprints' => $after, 'label' => self::label($after), 'measure' => $a],
            'overlap' => array_intersect($before, $after) !== [],
            'partial' => array_filter($all, fn (int $sprint): bool => ! $this->closed($sprint)) !== [],
            'headline' => [
                'growth' => $growth === null ? '—' : ($growth >= 0 ? '+' : '−').SprintKpis::round(abs($growth)).'%',
                'title' => $verb.$control,
                'from' => self::decimal($b['donePerSprint'], 0),
                'to' => self::decimal($a['donePerSprint'], 0),
            ],
            'hero' => array_map(fn (array $spec): array => [
                'title' => $spec['title'],
                'after' => self::format($spec['format'], $a[$spec['key']]),
                'before' => self::format($spec['format'], $b[$spec['key']]),
                'tag' => $this->tag($spec, $b, $a),
            ], self::HERO),
            'fronts' => array_map(function (array $front) use ($b, $a): array {
                $main = $front['main'];
                $change = self::change($main['kind'], $b[$main['key']], $a[$main['key']], $main['better']);

                return [
                    'title' => $front['title'],
                    'unit' => $front['unit'],
                    'label' => $main['label'],
                    'before' => self::format($main['format'], $b[$main['key']]),
                    'after' => self::format($main['format'], $a[$main['key']]),
                    'badge' => $change['text'],
                    'good' => $change['good'],
                    'sub' => [
                        'label' => $front['sub']['label'],
                        'before' => self::format($front['sub']['format'], $b[$front['sub']['key']]),
                        'after' => self::format($front['sub']['format'], $a[$front['sub']['key']]),
                    ],
                ];
            }, self::FRONTS),
            'note' => $note,
            'chart' => $this->chartSprints($before, $after),
        ];
    }

    /**
     * Pontos dos gráficos: do início do "antes" ao fim do "após" (as do meio são "gap").
     *
     * @param  list<int>  $before
     * @param  list<int>  $after
     * @return list<array{sprint: int, total: int, done: int, productivity: ?float, group: string}>
     */
    public function chartSprints(array $before, array $after): array
    {
        if ($before === [] && $after === []) {
            return [];
        }
        $first = min($before[0] ?? PHP_INT_MAX, $after[0] ?? PHP_INT_MAX);
        $last = max($before === [] ? PHP_INT_MIN : end($before), $after === [] ? PHP_INT_MIN : end($after));
        $points = [];
        foreach ($this->startedSprints() as $sprint) {
            if ($sprint < $first || $sprint > $last) {
                continue;
            }
            $metrics = $this->measure([$sprint]);
            $points[] = [
                'sprint' => $sprint,
                'total' => $metrics['cards'],
                'done' => SprintKpis::round($metrics['donePerSprint']),
                'productivity' => $metrics['productivity'],
                'group' => in_array($sprint, $after, true) ? 'after' : (in_array($sprint, $before, true) ? 'before' : 'gap'),
            ];
        }

        return $points;
    }

    /**
     * Selo do indicador do topo: "meta 90% ✓" quando a meta foi atingida; senão o selo da variação.
     *
     * @param  array{key: string, kind: string, better: string, target?: int}  $spec
     * @param  array<string, float|int|null>  $before
     * @param  array<string, float|int|null>  $after
     * @return array{text: string, good: ?bool}
     */
    private function tag(array $spec, array $before, array $after): array
    {
        $change = self::change($spec['kind'], $before[$spec['key']], $after[$spec['key']], $spec['better']);
        if (isset($spec['target']) && $after[$spec['key']] !== null && $after[$spec['key']] >= $spec['target']) {
            return ['text' => "meta {$spec['target']}% ✓", 'good' => $change['good']];
        }

        return $change;
    }

    /**
     * @param  list<int>  $sprints
     */
    public static function label(array $sprints): string
    {
        if ($sprints === []) {
            return '';
        }

        return count($sprints) === 1 ? "Sprint {$sprints[0]}" : 'Sprints '.$sprints[0].' a '.end($sprints).' ('.count($sprints).')';
    }

    /** Formatos do infográfico: count, percent, percentFine, hours e signed. */
    public static function format(string $format, float|int|null $value): string
    {
        return match ($format) {
            'count' => self::decimal($value, $value >= 10 ? 0 : 1),
            'percent' => $value === null ? '—' : self::decimal($value, $value >= 10 ? 0 : 1).'%',
            'percentFine' => $value === null ? '—' : self::decimal($value, 1).'%',
            'hours' => self::decimal($value, 0).'h',
            'signed' => $value === null ? '—' : ($value >= 0 ? '+' : '−').self::decimal(abs($value), 1).'%',
        };
    }

    /** decimal do front: arredonda (Math.round) e troca o ponto pela vírgula, sem zeros à direita. */
    public static function decimal(float|int|null $value, int $digits = 1): string
    {
        if ($value === null) {
            return '—';
        }
        $factor = 10 ** $digits;
        $rounded = floor($value * $factor + 0.5) / $factor;

        return str_replace('.', ',', (string) ($rounded == (int) $rounded ? (int) $rounded : $rounded));
    }

    private static function pct(int $part, int $whole): ?float
    {
        return $whole > 0 ? $part / $whole * 100 : null;
    }

    private static function filled(mixed $value): bool
    {
        return trim((string) ($value ?? '')) !== '';
    }

    /** number do front: texto com vírgula ou ponto vira número; o que não é número vira 0. */
    private static function number(mixed $value): float
    {
        $parsed = str_replace(',', '.', trim((string) ($value ?? '')));

        return is_numeric($parsed) ? (float) $parsed : 0.0;
    }
}
