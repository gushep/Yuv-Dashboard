<?php

namespace App\Metrics;

use App\Support\SprintRules;
use Collator;

/** Os resultados-chave, capacidade e XP da tela public/okrs.js. Horas em minutos. */
final class Okrs
{
    public const OBJECTIVE = 'Aumentar capacidade de entrega, reduzir retrabalho e melhorar previsibilidade das sprints e do backlog.';

    public const TARGETS = [
        'productivity' => ['target' => 90, 'better' => 'higher'],
        'rework' => ['target' => 10, 'better' => 'lower'],
        'backlog' => ['target' => 80, 'better' => 'higher'],
    ];

    private readonly Capacity $capacity;

    public function __construct(private readonly MetricsData $data)
    {
        $this->capacity = Capacity::for($data);
    }

    /** @return list<int> */
    public function startedSprints(): array
    {
        return array_values(array_filter($this->data->sprints, fn (int $sprint): bool => ($this->data->datesOf($sprint)['start'] ?? null) !== null
            && $this->data->datesOf($sprint)['start'] <= $this->data->today()));
    }

    /** @return list<int> */
    public function between(int $from, int $to): array
    {
        return array_values(array_filter($this->startedSprints(), fn (int $sprint): bool => $sprint >= min($from, $to) && $sprint <= max($from, $to)));
    }

    /** @param list<int> $sprints
     * @return list<array<string, mixed>>
     */
    private function rows(array $sprints): array
    {
        return array_values(array_filter($this->data->rows, fn (array $row): bool => in_array((int) $row['Sprint'], $sprints, true)));
    }

    /** @return array{value: ?float, part: int|float, whole: int|float, level: string, attainment: ?float} */
    private static function result(string $key, int|float $part, int|float $whole): array
    {
        $value = self::ratio($part, $whole);

        return ['value' => $value, 'part' => $part, 'whole' => $whole, 'level' => self::level($key, $value), 'attainment' => self::attainment($key, $value)];
    }

    /**
     * Atingimento do resultado-chave (0 a 100%, uma casa), igual ao okrs.js: maior é melhor = valor ÷ meta, com teto
     * de 100%; menor é melhor = 100% até a meta, caindo em linha até 0% no dobro da meta. Null sem dados.
     */
    public static function attainment(string $key, int|float|null $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $target = self::TARGETS[$key];
        $raw = $target['better'] === 'higher'
            ? min(100, $value / $target['target'] * 100)
            : ($value <= $target['target'] ? 100 : max(0, (2 * $target['target'] - $value) / $target['target'] * 100));

        return SprintRules::round($raw * 10) / 10.0;
    }

    /**
     * Progresso do objetivo: média do atingimento dos resultados-chave com dados (todos com peso 1). Null sem dados.
     *
     * @param  array<string, array{attainment: ?float}>  $results
     */
    public static function progress(array $results): ?float
    {
        $values = array_values(array_filter(array_map(fn (string $key): ?float => $results[$key]['attainment'] ?? null, array_keys(self::TARGETS)), fn (?float $value): bool => $value !== null));

        return $values === [] ? null : SprintRules::round(array_sum($values) / count($values) * 10) / 10.0;
    }

    /** Percentual com uma casa (Math.round(part / whole × 1000) ÷ 10), ou null sem denominador. */
    private static function ratio(int|float $part, int|float $whole): ?float
    {
        return $whole > 0 ? SprintRules::round($part / $whole * 1000) / 10.0 : null;
    }

    /** Situação do resultado-chave contra a meta: verde atingido, amarelo perto (até 5 pontos), vermelho fora, none sem dados. */
    public static function level(string $key, int|float|null $value): string
    {
        if ($value === null) {
            return 'none';
        }
        $target = self::TARGETS[$key];
        $gap = $target['better'] === 'higher' ? $target['target'] - $value : $value - $target['target'];

        return $gap <= 0 ? 'green' : ($gap <= 5 ? 'yellow' : 'red');
    }

    /** Variação percentual arredondada entre dois valores, com sinal (▲ +x% / ▼ −x%), ou "—" sem base. */
    private static function change(int|float $now, int|float $then, string $up = '▲ +', string $down = '▼ −'): string
    {
        return $then ? ($now >= $then ? $up : $down).SprintRules::round(abs($now - $then) / $then * 100).'%' : '—';
    }

    /** Número com uma casa e vírgula, como a tela (11 → "11"; 4,5 → "4,5"). */
    private static function oneDecimal(int|float $value): string
    {
        $rounded = SprintRules::round($value * 10) / 10;

        return str_replace('.', ',', (string) (floor($rounded) == $rounded ? (int) $rounded : $rounded));
    }

    /**
     * Resumo do crescimento: média das últimas 3 sprints encerradas contra as 3 anteriores (com 6 ou mais encerradas) ou
     * a última contra a anterior. Mesmo texto da tela, sem o negrito.
     *
     * @param  list<array{sprint: int, total: int, done: int}>  $series
     */
    public function growthSummary(array $series): string
    {
        $closed = array_values(array_filter($series, fn (array $item): bool => ! $this->inProgress($item['sprint'])));
        $size = count($closed) >= 6 ? 3 : (count($closed) >= 2 ? 1 : 0);
        if (! $size) {
            return 'Ainda não há sprints encerradas suficientes para comparar.';
        }
        $recent = array_slice($closed, -$size);
        $before = array_slice($closed, -$size * 2, $size);
        $average = fn (array $list, string $key): float => array_sum(array_column($list, $key)) / count($list);
        $delta = fn (float $now, float $then): string => $then ? ' ('.($now >= $then ? '+' : '−').SprintRules::round(abs($now - $then) / $then * 100).'%)' : '';
        [$doneNow, $doneThen, $totalNow, $totalThen] = [$average($recent, 'done'), $average($before, 'done'), $average($recent, 'total'), $average($before, 'total')];
        $which = $size === 3
            ? 'Últimas 3 sprints encerradas ('.implode(', ', array_column($recent, 'sprint')).') contra as 3 anteriores ('.implode(', ', array_column($before, 'sprint')).')'
            : "Sprint {$recent[0]['sprint']} contra a {$before[0]['sprint']}";

        return "{$which}: ".self::oneDecimal($doneNow).' entregas por sprint contra '.self::oneDecimal($doneThen).$delta($doneNow, $doneThen)
            .' e '.self::oneDecimal($totalNow).' cards registrados contra '.self::oneDecimal($totalThen).$delta($totalNow, $totalThen).'.';
    }

    /**
     * Destaques do período (infográfico): mais XP, mais entregas, melhor precisão (só com 3+ entregas) e quem não teve
     * retrabalho entre as pessoas com cards.
     *
     * @param  list<array<string, mixed>>  $people  saída de people()
     * @return array{xp: ?array{value: int|float, names: list<string>}, deliveries: ?array{value: int|float, names: list<string>}, precision: ?array{value: int|float, names: list<string>}, clean: list<string>, withCards: int}
     */
    public static function highlights(array $people): array
    {
        $top = function (callable $read, int $minimum = 1) use ($people): ?array {
            $ranked = array_values(array_filter($people, fn (array $item): bool => ($read($item) ?? -1) >= $minimum));
            if (! $ranked) {
                return null;
            }
            $best = max(array_map($read, $ranked));

            return ['value' => $best, 'names' => array_values(array_map(fn (array $item): string => $item['name'], array_filter($ranked, fn (array $item): bool => $read($item) === $best)))];
        };
        $withCards = array_values(array_filter($people, fn (array $item): bool => $item['results']['rework']['whole'] > 0));

        return [
            'xp' => $top(fn (array $item): ?int => $item['score']['xp'] ?? null),
            'deliveries' => $top(fn (array $item): int|float => $item['results']['productivity']['part']),
            'precision' => $top(fn (array $item): ?int => ($item['score']['delivered'] ?? 0) >= 3 ? $item['score']['precision'] : null, 0),
            'clean' => array_values(array_map(fn (array $item): string => $item['name'], array_filter($withCards, fn (array $item): bool => $item['results']['rework']['part'] === 0))),
            'withCards' => count($withCards),
        ];
    }

    /**
     * Tendência do infográfico: o período contra o período anterior do mesmo tamanho (entregas, cards registrados e
     * produtividade em pontos percentuais). Null quando não há período anterior.
     *
     * @param  list<int>  $sprints
     * @return array{label: string, before: list<int>, done: array{now: int, then: int, change: string}, total: array{now: int, then: int, change: string}, productivity: array{now: ?float, then: ?float, change: string}}|null
     */
    public function trend(array $sprints): ?array
    {
        $list = $this->startedSprints();
        $first = $sprints ? array_search($sprints[0], $list, true) : false;
        if ($first === false || $first < count($sprints)) {
            return null;
        }
        $before = array_slice($list, $first - count($sprints), count($sprints));
        $kpis = new SprintKpis($this->data);
        $now = $kpis->metricsOf($this->rows($sprints));
        $previous = $kpis->metricsOf($this->rows($before));
        [$productivityNow, $productivityThen] = [self::ratio($now['done'], $now['tot']), self::ratio($previous['done'], $previous['tot'])];
        $points = $productivityNow === null || $productivityThen === null ? '—'
            : ($productivityNow >= $productivityThen ? '▲ +' : '▼ −').str_replace('.', ',', (string) (SprintRules::round(abs($productivityNow - $productivityThen) * 10) / 10)).' p.p.';

        return [
            'label' => count($before) === 1 ? "Sprint {$before[0]}" : 'Sprints '.$before[0].' a '.end($before),
            'before' => $before,
            'done' => ['now' => $now['done'], 'then' => $previous['done'], 'change' => self::change($now['done'], $previous['done'])],
            'total' => ['now' => $now['tot'], 'then' => $previous['tot'], 'change' => self::change($now['tot'], $previous['tot'])],
            'productivity' => ['now' => $productivityNow, 'then' => $productivityThen, 'change' => $points],
        ];
    }

    /**
     * Números do infográfico do período: resultados-chave atingidos, fatos (cards, entregas, retrabalho, horas e
     * pessoas), origem dos cards, destaques e tendência.
     *
     * @param  list<int>  $sprints
     * @param  array<string, mixed>  $results  saída de compute()
     * @param  list<array<string, mixed>>  $people  saída de people()
     * @return array<string, mixed>
     */
    public function infographic(array $sprints, array $results, array $people): array
    {
        $metrics = (new SprintKpis($this->data))->metricsOf($this->rows($sprints));

        return [
            'hit' => count(array_filter(array_keys(self::TARGETS), fn (string $key): bool => $results[$key]['level'] === 'green')),
            'of' => count(self::TARGETS),
            'facts' => ['cards' => $metrics['tot'], 'done' => $metrics['done'], 'rework' => $metrics['rework'], 'registered' => $results['backlog']['part'], 'people' => count($people)],
            'origins' => ['planned' => $metrics['planned'], 'unplanned' => $metrics['unplanned'], 'support' => $metrics['support']],
            'hours' => array_map(fn (array $part): array => ['kind' => $part['kind'], 'registered' => $part['part']], $results['backlog']['parts']),
            'highlights' => self::highlights($people),
            'trend' => $this->trend($sprints),
        ];
    }

    /** @param list<int> $sprints
     * @return array<string, mixed>
     */
    public function compute(array $sprints): array
    {
        $metrics = (new SprintKpis($this->data))->metricsOf($this->rows($sprints));
        $totals = array_fill_keys(['total', 'fullstack', 'support', 'projects'], ['capacity' => 0, 'registered' => 0]);
        foreach ($sprints as $sprint) {
            foreach ($this->capacity->sprintSummary($this->data, $sprint)['cards'] as $card) {
                $key = $card['kind'] === '' ? 'total' : $card['kind'];
                $totals[$key]['capacity'] += $card['capacity'];
                $totals[$key]['registered'] += $card['registered'];
            }
        }

        return [
            'productivity' => self::result('productivity', $metrics['done'], $metrics['tot']),
            'rework' => self::result('rework', $metrics['rework'], $metrics['tot']),
            'backlog' => [...self::result('backlog', $totals['total']['registered'], $totals['total']['capacity']),
                'parts' => array_map(fn (string $kind): array => ['kind' => $kind, ...self::result('backlog', $totals[$kind]['registered'], $totals[$kind]['capacity'])], ['fullstack', 'support', 'projects'])],
        ];
    }

    /** @param list<int> $sprints
     * @return list<array<string, mixed>>
     */
    public function people(array $sprints): array
    {
        $byName = [];
        $rows = $this->rows($sprints);
        foreach ($sprints as $sprint) {
            $developers = $this->capacity->model($this->data->rows, $sprint, $this->data->datesOf($sprint), $this->data->capacityOverrides, $this->data->today())['developers'];
            $fullstackCapacity = array_sum(array_column(array_filter($developers, fn (array $dev): bool => $dev['team'] !== 'projects'), 'capacity'));
            $reserve = $this->capacity->teamsConfigured() ? $this->data->supportReserveMinutes($sprint) : 0;
            foreach ($developers as $dev) {
                $key = SprintRules::normalize($dev['name']);
                $byName[$key] ??= ['name' => $dev['name'], 'team' => $dev['team'], 'capacity' => 0, 'registered' => 0, 'supportCapacity' => 0, 'supportRegistered' => 0];
                $byName[$key]['capacity'] += $dev['capacity'];
                $byName[$key]['registered'] += $dev['registered'];
                $byName[$key]['supportCapacity'] += $dev['team'] !== 'projects' && $fullstackCapacity > 0 ? $reserve * $dev['capacity'] / $fullstackCapacity : 0;
                $byName[$key]['supportRegistered'] += $dev['supportAllReal'];
            }
        }
        $people = array_values(array_filter($byName, fn (array $dev): bool => $dev['capacity'] > 0 || array_any($rows, fn (array $row): bool => Daily::ownedBy($row, $dev['name']))));
        $collator = class_exists(Collator::class) ? new Collator('pt_BR') : null;
        $order = ['fullstack' => 0, 'projects' => 1];
        usort($people, fn (array $a, array $b): int => (($order[$a['team']] ?? 9) <=> ($order[$b['team']] ?? 9)) ?: ($collator ? (int) $collator->compare($a['name'], $b['name']) : strcasecmp($a['name'], $b['name'])));
        $daily = new Daily($this->data, $this->capacity);
        $cards = array_merge(...array_map($daily->sprintCards(...), $sprints));

        return array_map(function (array $dev) use ($rows, $cards): array {
            $mine = array_values(array_filter($rows, fn (array $row): bool => Daily::ownedBy($row, $dev['name'])));
            $metrics = (new SprintKpis($this->data))->metricsOf($mine);

            return [...$dev, 'results' => [
                'productivity' => self::result('productivity', $metrics['done'], $metrics['tot']),
                'rework' => self::result('rework', $metrics['rework'], $metrics['tot']),
                'backlog' => [...self::result('backlog', $dev['registered'], $dev['capacity']), 'parts' => [
                    ['kind' => $dev['team'] === 'projects' ? 'projects' : 'fullstack', ...self::result('backlog', $dev['registered'] - $dev['supportRegistered'], $dev['capacity'] - $dev['supportCapacity'])],
                    ['kind' => 'support', ...self::result('backlog', $dev['supportRegistered'], $dev['supportCapacity'])],
                ]],
            ], 'score' => Daily::score(array_values(array_filter($cards, fn (array $row): bool => Daily::ownedBy($row, $dev['name']))))];
        }, $people);
    }

    public function inProgress(int $sprint): bool
    {
        $dates = $this->data->datesOf($sprint);

        return $dates !== null && $dates['start'] <= $this->data->today() && $dates['end'] >= $this->data->today();
    }

    /** @return array<string, mixed> */
    public function period(int $from, int $to): array
    {
        $sprints = $this->between($from, $to);
        $months = [];
        foreach ($this->startedSprints() as $sprint) {
            $month = substr($this->data->datesOf($sprint)['start'], 0, 7);
            $months[$month][] = $sprint;
        }
        krsort($months);
        $growth = array_map(function (int $sprint): array {
            $metrics = (new SprintKpis($this->data))->metricsOf($this->data->rowsOf($sprint));

            return ['sprint' => $sprint, 'partial' => $this->inProgress($sprint), 'planned' => $metrics['planned'], 'unplanned' => $metrics['unplanned'], 'support' => $metrics['support'], 'total' => $metrics['tot'], 'done' => $metrics['done']];
        }, array_slice($this->startedSprints(), -12));

        $results = $this->compute($sprints);
        $people = $this->people($sprints);

        return [
            'objective' => self::OBJECTIVE, 'targets' => self::TARGETS, 'sprints' => $sprints,
            'partial' => array_any($sprints, $this->inProgress(...)), 'progress' => self::progress($results), 'results' => $results,
            'people' => array_map(fn (array $dev): array => [...$dev, 'progress' => self::progress($dev['results'])], $people),
            'infographic' => $this->infographic($sprints, $results, $people),
            'growthSummary' => $this->growthSummary($growth),
            'perSprint' => array_map(fn (int $sprint): array => ['sprint' => $sprint, 'partial' => $this->inProgress($sprint), 'results' => $this->compute([$sprint])], $sprints),
            'months' => array_map(fn (string $month, array $list): array => ['month' => $month, 'sprints' => $list, 'partial' => array_any($list, $this->inProgress(...)), 'results' => $this->compute($list)], array_keys(array_slice($months, 0, 6, true)), array_values(array_slice($months, 0, 6, true))),
            'growth' => $growth,
        ];
    }
}
