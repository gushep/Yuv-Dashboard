<?php

namespace App\Metrics;

use App\Support\SprintRules;
use App\Support\TeamRules;
use Collator;

/**
 * Cálculos da tela Capacity no servidor, espelho de public/capacity.js (model, teamTotals, outsideTotals,
 * supportTotals e os cards de summaryFor). Tudo em minutos, com os mesmos arredondamentos das telas.
 *
 * @phpstan-type Developer array{name: string, team: ?string, inheritedFrom: ?int, rows: list<array<string, mixed>>, capacity: int, elapsed: ?int, plannedEstimate: int, completedEstimate: int, missingEstimate: int, unplannedEstimate: int, supportEstimate: int, otherEstimate: int, missingUnplannedEstimate: int, totalEstimate: int, realPlanned: int, realUnplanned: int, realSupport: int, otherUnplanned: int, registered: int, knownReal: int, missingReal: int, knownPlanned: int, knownSupport: int, knownOther: int, supportAllEstimate: int, supportAllReal: int, utilization: ?float, delivery: ?float, supportImpact: ?float}
 * @phpstan-type Model array{developers: list<Developer>, pending: list<array{row: array<string, mixed>, reasons: list<string>}>, unassigned: list<array<string, mixed>>, outside: list<array<string, mixed>>, selectedCount: int}
 * @phpstan-type TeamTotals array{total: int, reserve: int, fullstack: array{people: int, capacity: int, net: int, estimate: int, registered: int}, projects: array{people: int, capacity: int, estimate: int, registered: int}}
 * @phpstan-type Card array{label: string, kind: string, capacity: int, estimate: int, registered: int, estimateLevel: string, registeredLevel: string}
 */
final class Capacity
{
    /** Ordem dos times nas tabelas: Full stack, Projetos e, por último, sem time. */
    private const TEAM_ORDER = [TeamRules::FULLSTACK => 0, TeamRules::PROJECTS => 1];

    public function __construct(private readonly TeamRules $teams) {}

    /**
     * Capacity com os times e a capacidade padrão das Configurações.
     */
    public static function for(MetricsData $data): self
    {
        return new self(SprintRules::teamRules($data->settings));
    }

    /**
     * Horas de um campo do Monday em minutos: número decimal de horas, "7h30", "7h", "30m" ou "7:30". Vazio ou
     * inválido devolve null (não é zero).
     */
    public static function minutes(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) && $value >= 0 ? SprintRules::round($value * 60) : null;
        }
        if (is_bool($value) || is_array($value)) {
            return null;
        }
        $text = mb_strtolower(trim((string) $value));
        if (preg_match('/^(\d+)\s*h\s*(\d{1,2})?\s*m?$/', $text, $match)) {
            return (int) $match[1] * 60 + (int) ($match[2] ?? 0);
        }
        if (preg_match('/^(\d{1,2})\s*m$/', $text, $match)) {
            return (int) $match[1];
        }
        if (preg_match('/^(\d+):(\d{2})$/', $text, $match) && (int) $match[2] < 60) {
            return (int) $match[1] * 60 + (int) $match[2];
        }
        $decimal = (string) preg_replace('/,/', '.', $text, 1);
        if ($decimal === '') {
            return 0;
        }

        return is_numeric($decimal) && (float) $decimal >= 0 && is_finite((float) $decimal) ? SprintRules::round((float) $decimal * 60) : null;
    }

    /** Minutos no formato das telas: "7h", "7h30", "-1h05"; null vira "—". */
    public static function formatMinutes(float|int|null $value): string
    {
        if ($value === null) {
            return '—';
        }
        $sign = $value < 0 ? '-' : '';
        $abs = abs(SprintRules::round($value));
        $minutes = $abs % 60;

        return $sign.intdiv($abs, 60).'h'.($minutes ? str_pad((string) $minutes, 2, '0', STR_PAD_LEFT) : '');
    }

    /** Dias úteis (segunda a sexta) entre duas datas, inclusive; null quando as datas são inválidas. */
    public static function weekdays(?string $from, ?string $to): ?int
    {
        if (! SprintRules::validDate($from) || ! SprintRules::validDate($to) || $from > $to) {
            return null;
        }

        return count(SprintRules::workingDays($from, $to));
    }

    /**
     * Capacidade até hoje: total × dias úteis transcorridos ÷ dias úteis da sprint, com hoje completo.
     *
     * @param  array{start?: ?string, end?: ?string}|null  $dates
     */
    public static function elapsedMinutes(int $totalMinutes, ?array $dates, string $today): ?int
    {
        if (empty($dates['start']) || empty($dates['end'])) {
            return null;
        }
        $all = self::weekdays($dates['start'], $dates['end']);
        if (! $all) {
            return null;
        }
        if ($today < $dates['start']) {
            return 0;
        }
        if ($today >= $dates['end']) {
            return $totalMinutes;
        }

        return SprintRules::round($totalMinutes * self::weekdays($dates['start'], $today) / $all);
    }

    /** Semáforo de uso contra a capacidade (regra única do SprintRules). */
    public static function capacityLevel(float|int $used, float|int|null $capacity): string
    {
        return SprintRules::capacityLevel($used, $capacity);
    }

    /**
     * Horas por pessoa na sprint: cada card de um único responsável com time vai para a pessoa; cards compartilhados,
     * em várias sprints ou sem planejamento ficam em pendências; sem responsável e só de pessoas sem time, à parte.
     * Pessoas classificadas entram mesmo sem card.
     *
     * @param  list<array<string, mixed>>  $allRows
     * @param  array{start?: ?string, end?: ?string}|null  $dates
     * @param  array<string, mixed>  $overrides  "sprint|nome" => minutos
     * @return Model
     */
    public function model(array $allRows, int $sprint, ?array $dates, array $overrides, string $today): array
    {
        $sprintById = [];
        foreach ($allRows as $row) {
            if (! empty($row['Id']) && empty($row['AsOf'])) {
                $sprintById[(string) $row['Id']][(int) $row['Sprint']] = true;
            }
        }
        $unique = [];
        foreach ($allRows as $index => $row) {
            if ((int) $row['Sprint'] === $sprint) {
                $unique[! empty($row['Id']) ? "{$row['Id']}|{$sprint}" : "snapshot-{$index}"] = $row;
            }
        }
        $groups = [];
        $pending = [];
        $unassigned = [];
        $outside = [];
        foreach ($unique as $row) {
            $named = self::people($row['Responsavel'] ?? null);
            if ($named === []) {
                $unassigned[] = $row;

                continue;
            }
            $owners = array_values(array_filter($named, fn (string $name): bool => $this->teams->teamOf($name) !== null));
            if ($owners === []) {
                $outside[] = $row;

                continue;
            }
            $reasons = [];
            if (count($owners) > 1) {
                $reasons[] = 'vários responsáveis; sem apontamentos individuais';
            }
            if (! empty($row['Id']) && count($sprintById[(string) $row['Id']] ?? []) > 1) {
                $reasons[] = 'card em várias sprints; tempo total não separado por sprint';
            }
            if (self::planning($row['Planejada'] ?? null) === 'unknown') {
                $reasons[] = 'planejamento não informado';
            }
            if ($reasons !== []) {
                $pending[] = ['row' => $row, 'reasons' => $reasons];

                continue;
            }
            $groups[$owners[0]][] = $row;
        }
        $known = array_map(fn (int|string $name): string => SprintRules::normalize((string) $name), array_keys($groups));
        foreach ($this->teams->members() as $member) {
            if (! in_array(SprintRules::normalize($member['name']), $known, true)) {
                $groups[$member['name']] = [];
                $known[] = SprintRules::normalize($member['name']);
            }
        }
        $developers = [];
        foreach ($groups as $name => $rows) {
            $developers[] = $this->developer((string) $name, $rows, $sprint, $dates, $overrides, $today);
        }
        $collator = class_exists(Collator::class) ? new Collator('pt_BR') : null;
        usort($developers, fn (array $a, array $b): int => (self::TEAM_ORDER[$a['team']] ?? 9) <=> (self::TEAM_ORDER[$b['team']] ?? 9) ?: ($collator ? (int) $collator->compare($a['name'], $b['name']) : strcasecmp($a['name'], $b['name'])));

        return ['developers' => $developers, 'pending' => $pending, 'unassigned' => $unassigned, 'outside' => $outside, 'selectedCount' => count($unique)];
    }

    /**
     * Capacidade e horas de cada time. As horas de Full stack e Projetos não incluem os cards de Suporte, que ficam no
     * card do Bombeiro; a reserva do Bombeiro só sai do Full stack depois de classificar os times.
     *
     * @param  list<Developer>  $developers
     * @return TeamTotals
     */
    public function teamTotals(array $developers, int $reserveMinutes): array
    {
        $of = fn (string $team): array => array_values(array_filter($developers, fn (array $dev): bool => $dev['team'] === $team));
        $sum = fn (array $list, callable $read): int => array_sum(array_map($read, $list));
        $fullstack = $of(TeamRules::FULLSTACK);
        $projects = $of(TeamRules::PROJECTS);
        $reserve = $this->teams->configured() ? $reserveMinutes : 0;
        $fullstackCapacity = $sum($fullstack, fn (array $dev): int => $dev['capacity']);
        $projectsCapacity = $sum($projects, fn (array $dev): int => $dev['capacity']);
        $withoutSupport = fn (array $list): array => [
            'estimate' => $sum($list, fn (array $dev): int => $dev['totalEstimate'] - $dev['supportAllEstimate']),
            'registered' => $sum($list, fn (array $dev): int => $dev['registered'] - $dev['supportAllReal']),
        ];

        return [
            'total' => $fullstackCapacity + $projectsCapacity,
            'reserve' => $reserve,
            'fullstack' => ['people' => count($fullstack), 'capacity' => $fullstackCapacity, 'net' => max(0, $fullstackCapacity - $reserve), ...$withoutSupport($fullstack)],
            'projects' => ['people' => count($projects), 'capacity' => $projectsCapacity, ...$withoutSupport($projects)],
        ];
    }

    /**
     * Horas fora dos cards do time (compartilhados, sem responsável ou só de quem está sem time), sem os de Suporte.
     *
     * @param  Model  $model
     * @return array{estimate: int, registered: int}
     */
    public function outsideTotals(array $model): array
    {
        $rows = array_filter(
            [...array_column($model['pending'], 'row'), ...$model['unassigned'], ...$model['outside']],
            fn (array $row): bool => self::normalized($row['Tipo'] ?? null) !== 'suporte',
        );
        $estimate = 0;
        $registered = 0;
        foreach ($rows as $row) {
            if (! SprintRules::isRemoved($row['Status'] ?? null)) {
                $estimate += self::minutes($row['EstH'] ?? null) ?? 0;
            }
            $registered += self::minutes($row['RealH'] ?? null) ?? 0;
        }

        return ['estimate' => $estimate, 'registered' => $registered];
    }

    /**
     * Horas de sustentação da sprint, pela regra do Consumo de horas: cards de Tipo Suporte, uma vez por card, de
     * qualquer responsável (inclusive sem responsável ou sem time).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{cards: int, registered: int, estimate: int}
     */
    public static function supportTotals(array $rows, int $sprint): array
    {
        $unique = [];
        foreach ($rows as $index => $row) {
            if ((int) $row['Sprint'] === $sprint && self::normalized($row['Tipo'] ?? null) === 'suporte') {
                $unique[! empty($row['Id']) ? (string) $row['Id'] : "snapshot-{$index}"] = $row;
            }
        }
        $registered = 0;
        $estimate = 0;
        foreach ($unique as $row) {
            $registered += self::minutes($row['RealH'] ?? null) ?? 0;
            if (! SprintRules::isRemoved($row['Status'] ?? null)) {
                $estimate += self::minutes($row['EstH'] ?? null) ?? 0;
            }
        }

        return ['cards' => count($unique), 'registered' => $registered, 'estimate' => $estimate];
    }

    /**
     * Os quatro cards de horas das telas (Capacity, Consumo de horas e barra superior): Capacidade da sprint, Full stack
     * para a sprint (descontado o Bombeiro), Bombeiro (sustentação) e Projetos (IA), cada um com o previsto (Tempo
     * Estimado) e o registrado (Tempo Real) contra a capacidade do card. Cada hora conta num card só.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array{start?: ?string, end?: ?string}|null  $dates
     * @param  array<string, mixed>  $overrides
     * @return array{model: Model, totals: TeamTotals, support: array{cards: int, registered: int, estimate: int}, outside: array{estimate: int, registered: int}, cards: list<Card>}
     */
    public function summaryFor(array $rows, int $sprint, ?array $dates, array $overrides, int $reserveMinutes, string $today): array
    {
        $model = $this->model($rows, $sprint, $dates, $overrides, $today);
        $totals = $this->teamTotals($model['developers'], $reserveMinutes);
        $support = self::supportTotals($rows, $sprint);
        $cards = [
            ['label' => 'Capacidade da sprint', 'kind' => '', 'capacity' => $totals['total'],
                'estimate' => $totals['fullstack']['estimate'] + $support['estimate'] + $totals['projects']['estimate'],
                'registered' => $totals['fullstack']['registered'] + $support['registered'] + $totals['projects']['registered']],
            ['label' => 'Full stack para a sprint', 'kind' => 'fullstack', 'capacity' => $totals['fullstack']['net'], 'estimate' => $totals['fullstack']['estimate'], 'registered' => $totals['fullstack']['registered']],
            ['label' => 'Bombeiro (sustentação)', 'kind' => 'support', 'capacity' => $reserveMinutes, 'estimate' => $support['estimate'], 'registered' => $support['registered']],
            ['label' => 'Projetos (IA)', 'kind' => 'projects', 'capacity' => $totals['projects']['capacity'], 'estimate' => $totals['projects']['estimate'], 'registered' => $totals['projects']['registered']],
        ];
        $cards = array_map(fn (array $card): array => $card + [
            'estimateLevel' => SprintRules::capacityLevel($card['estimate'], $card['capacity']),
            'registeredLevel' => SprintRules::capacityLevel($card['registered'], $card['capacity']),
        ], $cards);

        return ['model' => $model, 'totals' => $totals, 'support' => $support, 'outside' => $this->outsideTotals($model), 'cards' => $cards];
    }

    /**
     * Os cards de horas de uma sprint com os dados do Metrics (horas por pessoa, reserva do Bombeiro e hoje).
     *
     * @return array{model: Model, totals: TeamTotals, support: array{cards: int, registered: int, estimate: int}, outside: array{estimate: int, registered: int}, cards: list<Card>}
     */
    public function sprintSummary(MetricsData $data, int $sprint): array
    {
        return $this->summaryFor($data->rows, $sprint, $data->datesOf($sprint), $data->capacityOverrides, $data->supportReserveMinutes($sprint), $data->today());
    }

    /**
     * Ritmo do time: horas registradas por dia útil projetadas até o fim da sprint, contra a capacidade de quem já
     * registrou horas. Espelho de teamPaceText, com os números no lugar do texto.
     *
     * @param  list<Developer>  $developers
     * @param  array{start?: ?string, end?: ?string}|null  $dates
     * @return array{tracked: int, registered: int, capacity: int, pace: ?array<string, mixed>}
     */
    public function teamPace(array $developers, ?array $dates, string $today): array
    {
        $tracked = array_values(array_filter($developers, fn (array $dev): bool => $dev['knownReal'] > 0));
        $registered = array_sum(array_column($tracked, 'registered'));
        $capacity = array_sum(array_column($tracked, 'capacity'));

        return ['tracked' => count($tracked), 'registered' => $registered, 'capacity' => $capacity, 'pace' => $tracked === [] ? null : SprintRules::pace($registered, $dates, $today, $capacity)];
    }

    /** Capacidade padrão das Configurações, em minutos. */
    public function defaultMinutes(): int
    {
        return $this->teams->capacityMinutes([], 0, '');
    }

    /** Time da pessoa (fullstack, projects ou null quando fica fora das métricas por pessoa). */
    public function teamOf(string $name): ?string
    {
        return $this->teams->teamOf($name);
    }

    public function teamsConfigured(): bool
    {
        return $this->teams->configured();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array{start?: ?string, end?: ?string}|null  $dates
     * @param  array<string, mixed>  $overrides
     * @return Developer
     */
    private function developer(string $name, array $rows, int $sprint, ?array $dates, array $overrides, string $today): array
    {
        $saved = SprintRules::capacityFor($overrides, $sprint, $name, $this->defaultMinutes());
        $capacity = $saved['minutes'];
        $elapsed = self::elapsedMinutes($capacity, $dates, $today);
        $result = ['name' => $name, 'team' => $this->teams->teamOf($name), 'inheritedFrom' => $saved['inherited'] ? $saved['fromSprint'] : null, 'rows' => $rows,
            'capacity' => $capacity, 'elapsed' => $elapsed, 'plannedEstimate' => 0, 'completedEstimate' => 0, 'missingEstimate' => 0,
            'unplannedEstimate' => 0, 'supportEstimate' => 0, 'otherEstimate' => 0, 'missingUnplannedEstimate' => 0, 'totalEstimate' => 0,
            'realPlanned' => 0, 'realUnplanned' => 0, 'realSupport' => 0, 'otherUnplanned' => 0, 'registered' => 0, 'knownReal' => 0, 'missingReal' => 0,
            'knownPlanned' => 0, 'knownSupport' => 0, 'knownOther' => 0, 'supportAllEstimate' => 0, 'supportAllReal' => 0];
        foreach ($rows as $row) {
            $category = self::planning($row['Planejada'] ?? null);
            $estimate = self::minutes($row['EstH'] ?? null);
            $real = self::minutes($row['RealH'] ?? null);
            $support = self::normalized($row['Tipo'] ?? null) === 'suporte';
            $removed = SprintRules::isRemoved($row['Status'] ?? null);
            // Suporte de qualquer planejamento: vai para o card do Bombeiro e sai do card do time.
            if ($support) {
                if ($estimate !== null && ! $removed) {
                    $result['supportAllEstimate'] += $estimate;
                }
                if ($real !== null) {
                    $result['supportAllReal'] += $real;
                }
            }
            if ($category === 'planned' && ! $removed) {
                if ($estimate === null) {
                    $result['missingEstimate']++;
                } else {
                    $result['plannedEstimate'] += $estimate;
                    if (SprintRules::isDone($row['Status'] ?? null)) {
                        $result['completedEstimate'] += $estimate;
                    }
                }
            } elseif ($category === 'unplanned' && ! $removed) {
                if ($estimate === null) {
                    $result['missingUnplannedEstimate']++;
                } elseif ($support) {
                    $result['supportEstimate'] += $estimate;
                } else {
                    $result['otherEstimate'] += $estimate;
                }
            }
            if ($real === null) {
                $result['missingReal']++;

                continue;
            }
            $result['knownReal']++;
            $result['registered'] += $real;
            if ($category === 'planned') {
                $result['realPlanned'] += $real;
                $result['knownPlanned']++;
            } elseif ($support) {
                $result['realSupport'] += $real;
                $result['knownSupport']++;
            } else {
                $result['otherUnplanned'] += $real;
                $result['knownOther']++;
            }
        }
        $result['realUnplanned'] = $result['realSupport'] + $result['otherUnplanned'];
        $result['unplannedEstimate'] = $result['supportEstimate'] + $result['otherEstimate'];
        $result['totalEstimate'] = $result['plannedEstimate'] + $result['unplannedEstimate'];
        $result['utilization'] = $result['knownReal'] && $elapsed > 0 ? $result['registered'] / $elapsed * 100 : null;
        $result['delivery'] = $result['plannedEstimate'] > 0 ? $result['completedEstimate'] / $result['plannedEstimate'] * 100 : null;
        $result['supportImpact'] = $result['registered'] > 0 ? $result['realSupport'] / $result['registered'] * 100 : null;

        return $result;
    }

    /**
     * Responsáveis de um card (coluna Pessoas, separada por vírgula).
     *
     * @return list<string>
     */
    public static function people(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn (string $name): bool => $name !== ''));
    }

    /** Texto sem acento e em minúsculas, como o `normalized` do capacity.js (sem juntar espaços internos). */
    public static function normalized(mixed $value): string
    {
        $text = mb_strtolower(trim((string) $value));
        $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);

        return (string) preg_replace('/\p{Mn}/u', '', $decomposed === false ? $text : $decomposed);
    }

    /**
     * Planejamento do card: planejado, não planejado ou não informado.
     *
     * @return 'planned'|'unplanned'|'unknown'
     */
    public static function planning(mixed $value): string
    {
        $text = self::normalized($value);

        return match ($text) {
            'planejada', 'planejado' => 'planned',
            'nao planejado', 'nao planejada' => 'unplanned',
            default => 'unknown',
        };
    }
}
