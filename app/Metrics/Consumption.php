<?php

namespace App\Metrics;

use App\Support\SprintRules;
use App\Support\TeamRules;
use Collator;

/**
 * Números da tela Consumo de horas, espelho de public/consumption.js: horas executadas (Tempo Real) da sprint,
 * separadas em Sprint e Suporte, por pessoa e por time, sem rateio de horas compartilhadas, e a reserva do Bombeiro.
 *
 * @phpstan-type Task array{row: array<string, mixed>, group: 'sprint'|'support', minutes: ?int, reason?: string}
 * @phpstan-type Totals array{sprint: int, support: int, total: int, tasks: list<Task>}
 * @phpstan-type Developer array{name: string, team: ?string, sprint: int, support: int, total: int, tasks: list<Task>}
 * @phpstan-type Model array{macro: Totals, developers: list<Developer>, attributed: Totals, teams: array{fullstack: Totals, projects: Totals}, outside: Totals, unassigned: Totals, missingType: int, missingReal: int, missingId: int, multiSprint: int}
 */
final class Consumption
{
    public function __construct(private readonly TeamRules $teams) {}

    public static function for(MetricsData $data): self
    {
        return new self(SprintRules::teamRules($data->settings));
    }

    /**
     * Duração do Tempo Real em minutos, como o Forecast.duration das telas: "7h30", "7h", "30m", "7:30" ou horas
     * decimais. Minutos acima de 59 ou texto inválido devolvem null (sem registro).
     */
    public static function duration(mixed $value): ?int
    {
        if ($value === null || is_bool($value) || is_array($value) || trim((string) $value) === '') {
            return null;
        }
        $text = mb_strtolower(trim((string) $value));
        if (preg_match('/^(\d+)h(?:\s*(\d{1,2})m?)?$/', $text, $match)) {
            return (int) ($match[2] ?? 0) < 60 ? (int) $match[1] * 60 + (int) ($match[2] ?? 0) : null;
        }
        if (preg_match('/^(\d+)m$/', $text, $match)) {
            return (int) $match[1];
        }
        if (preg_match('/^(\d+):(\d{2})$/', $text, $match)) {
            return (int) $match[2] < 60 ? (int) $match[1] * 60 + (int) $match[2] : null;
        }
        $hours = (string) preg_replace('/,/', '.', $text, 1);

        return is_numeric($hours) && (float) $hours >= 0 && is_finite((float) $hours) ? SprintRules::round((float) $hours * 60) : null;
    }

    /** Minutos como "7h30"; sem registro (null) vira "Sem registro". */
    public static function format(float|int|null $minutes): string
    {
        if ($minutes === null) {
            return 'Sem registro';
        }
        $value = SprintRules::round(abs($minutes));
        $rest = $value % 60;

        return intdiv($value, 60).'h'.($rest ? str_pad((string) $rest, 2, '0', STR_PAD_LEFT) : '');
    }

    /** Percentual como nas telas: até uma casa decimal, vírgula decimal ("67,6%"); null vira "—". */
    public static function percent(float|int|null $value): string
    {
        if ($value === null) {
            return '—';
        }
        $rounded = round((float) $value, 1);

        return number_format($rounded, fmod($rounded, 1.0) == 0.0 ? 0 : 1, ',', '.').'%';
    }

    /**
     * Horas executadas da sprint: o macro soma tudo; a composição separa Desenvolvimento (Full stack), Projetos, cards só
     * de pessoas sem time e os sem atribuição individual. Cards sem Id ficam fora (sem identificação auditável) e cada
     * card conta uma vez.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return Model
     */
    public function model(array $rows, int $sprint): array
    {
        $unique = [];
        $seenSprints = [];
        $groups = [];
        $macro = self::emptyTotals();
        $unassigned = self::emptyTotals();
        $outside = self::emptyTotals();
        $missingType = 0;
        $missingReal = 0;
        $missingId = 0;
        $multiSprint = 0;
        foreach ($rows as $row) {
            $id = trim((string) ($row['Id'] ?? ''));
            if ($id !== '' && empty($row['AsOf'])) {
                $seenSprints[$id][(int) $row['Sprint']] = true;
            }
            if ((int) $row['Sprint'] !== $sprint) {
                continue;
            }
            if ($id === '') {
                $missingId++;

                continue;
            }
            $unique[$id] ??= $row;
        }
        foreach ($unique as $id => $row) {
            $group = self::normalize($row['Tipo'] ?? null) === 'suporte' ? 'support' : 'sprint';
            $minutes = self::duration($row['RealH'] ?? null);
            $people = array_values(array_unique(Capacity::people($row['Responsavel'] ?? null)));
            $task = ['row' => $row, 'group' => $group, 'minutes' => $minutes];
            if (self::normalize($row['Tipo'] ?? null) === '') {
                $missingType++;
            }
            if ($minutes === null) {
                $missingReal++;
            }
            if (count($seenSprints[(string) $id] ?? []) > 1) {
                $multiSprint++;
            }
            self::add($macro, $task);
            $counted = array_values(array_filter($people, fn (string $name): bool => $this->teams->teamOf($name) !== null));
            if ($people !== [] && $counted === []) {
                $task['reason'] = 'Responsável sem time nas Configurações';
                self::add($outside, $task);
            } elseif (count($counted) === 1) {
                $groups[$counted[0]] ??= ['name' => $counted[0], 'team' => $this->teams->teamOf($counted[0]), ...self::emptyTotals()];
                self::add($groups[$counted[0]], $task);
            } else {
                $task['reason'] = $counted !== [] ? 'Tempo compartilhado entre vários responsáveis' : 'Sem responsável';
                self::add($unassigned, $task);
            }
        }
        $collator = class_exists(Collator::class) ? new Collator('pt_BR') : null;
        $developers = array_values($groups);
        usort($developers, fn (array $a, array $b): int => $b['total'] <=> $a['total'] ?: ($collator ? (int) $collator->compare($a['name'], $b['name']) : strcasecmp($a['name'], $b['name'])));
        $team = fn (string $name): array => self::sum(array_filter($developers, fn (array $dev): bool => $dev['team'] === $name));

        return [
            'macro' => $macro, 'developers' => $developers, 'attributed' => self::sum($developers),
            'teams' => ['fullstack' => $team(TeamRules::FULLSTACK), 'projects' => $team(TeamRules::PROJECTS)],
            'outside' => $outside, 'unassigned' => $unassigned,
            'missingType' => $missingType, 'missingReal' => $missingReal, 'missingId' => $missingId, 'multiSprint' => $multiSprint,
        ];
    }

    /**
     * Participação de Sprint e Suporte nas horas, em percentual; sem horas, null.
     *
     * @param  array{sprint: int|float, support: int|float, total: int|float}  $totals
     * @return array{sprint: ?float, support: ?float}
     */
    public static function shares(array $totals): array
    {
        return [
            'sprint' => $totals['total'] ? $totals['sprint'] / $totals['total'] * 100 : null,
            'support' => $totals['total'] ? $totals['support'] / $totals['total'] * 100 : null,
        ];
    }

    /**
     * Composição de um indicador do topo: Desenvolvimento, Projetos e, quando houver, sem time e sem atribuição.
     *
     * @param  Model  $result
     * @param  'sprint'|'support'|'total'  $key
     */
    public static function compositionText(array $result, string $key): string
    {
        $parts = ['Desenvolvimento '.self::format($result['teams']['fullstack'][$key]), 'Projetos '.self::format($result['teams']['projects'][$key])];
        if ($result['outside'][$key]) {
            $parts[] = 'Sem time '.self::format($result['outside'][$key]);
        }
        if ($result['unassigned'][$key]) {
            $parts[] = 'Sem atribuição '.self::format($result['unassigned'][$key]);
        }

        return implode(' · ', $parts);
    }

    /**
     * Consumo da reserva do Bombeiro: percentual consumido, disponível e excesso, e o saldo em minutos. Sem reserva,
     * os percentuais ficam nulos.
     *
     * @return array{consumed: ?float, available: ?float, excess: ?float, balance: int|float, scale: int|float, marker: ?float}
     */
    public static function reserveModel(int|float $support, int|float $reserve): array
    {
        if (! $reserve) {
            return ['consumed' => null, 'available' => null, 'excess' => null, 'balance' => -$support, 'scale' => max($support, 1), 'marker' => null];
        }
        $scale = max($reserve, $support) * 1.15;

        return [
            'consumed' => $support / $reserve * 100,
            'available' => max(0, ($reserve - $support) / $reserve * 100),
            'excess' => $support > $reserve ? ($support - $reserve) / $reserve * 100 : 0,
            'balance' => $reserve - $support,
            'scale' => $scale,
            'marker' => $reserve / $scale * 100,
        ];
    }

    /**
     * Ritmo da reserva: projeta o consumo de suporte até o fim da sprint e a data em que a reserva acaba. O risco
     * acende quando a reserva acaba antes do último dia.
     *
     * @param  array{start?: ?string, end?: ?string}|null  $dates
     * @return array{text: string, risk: bool, pace: array<string, mixed>}
     */
    public static function reservePace(int|float $support, ?array $dates, int|float $reserve, string $today): array
    {
        $pace = SprintRules::pace($support, $dates, $today, $reserve);
        $day = fn (string $value): string => implode('/', array_slice(array_reverse(explode('-', $value)), 0, 2));
        $result = fn (string $text, bool $risk = false): array => ['text' => $text, 'risk' => $risk, 'pace' => $pace];
        if ($pace['status'] === 'unavailable') {
            return $result('Ritmo indisponível: datas da sprint ausentes no Monday.');
        }
        if ($pace['status'] === 'not-started') {
            return $result('Sprint ainda não começou: sem ritmo de consumo.');
        }
        if ($pace['status'] === 'finished') {
            return $result('Sprint encerrada: média de '.self::format($pace['daily']).' de suporte por dia útil.');
        }
        if (! $reserve) {
            return $result('Ritmo atual: '.self::format($pace['daily']).' por dia útil · '.self::format($pace['projected']).' previstas até o fim da sprint.');
        }
        $risk = $pace['exhaustionDate'] !== null && $pace['exhaustionDate'] < $pace['sprintEnd'];
        $ending = $pace['exhaustionDate'] === null ? 'a reserva é suficiente até o fim da sprint'
            : ($risk ? 'a reserva acaba em '.$day($pace['exhaustionDate']).', antes do fim da sprint ('.$day($pace['sprintEnd']).')' : 'a reserva acaba no último dia da sprint');

        return $result('No ritmo atual ('.self::format($pace['daily']).' por dia útil): '.self::format($pace['projected']).' até o fim da sprint, '.self::percent($pace['projectedPercent']).' da reserva; '.$ending.'.', $risk);
    }

    /**
     * Sprints da tela: as dos cards e as do cronograma do Monday, da mais recente para a mais antiga.
     *
     * @return list<int>
     */
    public static function sprints(MetricsData $data): array
    {
        $sprints = array_values(array_unique(array_filter([...$data->sprints, ...array_map('intval', array_keys($data->sprintDates))], fn (int $sprint): bool => $sprint > 0)));
        rsort($sprints);

        return $sprints;
    }

    /**
     * Tudo o que a tela Consumo de horas mostra de uma sprint: total e composição, reserva do Bombeiro e ritmo, os
     * cards de horas do Capacity, as tabelas Desenvolvimento e Projetos, os sem atribuição e a qualidade dos dados.
     *
     * @return array<string, mixed>
     */
    public function sprint(MetricsData $data, int $sprint): array
    {
        $result = $this->model($data->rows, $sprint);
        $totals = $result['macro'];
        $reserve = $data->supportReserveMinutes($sprint);
        $dates = $data->datesOf($sprint);
        $others = self::sum([$result['unassigned'], $result['outside']]);

        return [
            'sprint' => $sprint,
            'dates' => $dates,
            'model' => $result,
            'shares' => self::shares($totals),
            'composition' => ['total' => self::compositionText($result, 'total'), 'sprint' => self::compositionText($result, 'sprint'), 'support' => self::compositionText($result, 'support')],
            'projectsSupport' => $result['teams']['projects']['support'],
            'reserve' => ['minutes' => $reserve, ...self::reserveModel($totals['support'], $reserve)],
            'reservePace' => self::reservePace($totals['support'], $dates, $reserve, $data->today()),
            'summary' => Capacity::for($data)->summaryFor($data->rows, $sprint, $dates, $data->capacityOverrides, $reserve, $data->today()),
            'others' => $others,
        ];
    }

    /**
     * @return array{sprint: int, support: int, total: int, tasks: list<Task>}
     */
    private static function emptyTotals(): array
    {
        return ['sprint' => 0, 'support' => 0, 'total' => 0, 'tasks' => []];
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  Task  $task
     */
    private static function add(array &$target, array $task): void
    {
        $target[$task['group']] += $task['minutes'] ?? 0;
        $target['total'] += $task['minutes'] ?? 0;
        $target['tasks'][] = $task;
    }

    /**
     * @param  iterable<array<string, mixed>>  $list
     * @return Totals
     */
    private static function sum(iterable $list): array
    {
        $total = self::emptyTotals();
        foreach ($list as $item) {
            $total['sprint'] += $item['sprint'];
            $total['support'] += $item['support'];
            $total['total'] += $item['total'];
            $total['tasks'] = [...$total['tasks'], ...$item['tasks']];
        }

        return $total;
    }

    /** Texto em minúsculas e com os espaços juntados, como o `normalize` do consumption.js (acentos ficam). */
    private static function normalize(mixed $value): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim((string) $value)));
    }
}
