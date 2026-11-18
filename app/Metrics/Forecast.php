<?php

namespace App\Metrics;

use App\Support\SprintRules;
use App\Support\TeamRules;
use Collator;

/**
 * Previsibilidade (espelho de public/forecast.js): horas que faltam para concluir as tarefas abertas de cada pessoa
 * contra a capacidade que resta na sprint, a partir da data de referência. Só leitura; não grava nada.
 */
final class Forecast
{
    /** Ordem das situações na tabela: excesso primeiro, sem demandas por último. */
    private const RANK = ['over' => 0, 'limit' => 1, 'unknown' => 2, 'within' => 3, 'low' => 4, 'empty' => 5];

    public const LABELS = ['over' => 'Acima da capacidade', 'limit' => 'No limite', 'unknown' => 'Sem previsão', 'within' => 'Dentro da capacidade', 'low' => 'Baixa carga prevista', 'empty' => 'Sem demandas abertas'];

    /**
     * Previsão da sprint como a tela mostra: pessoas, tarefas sem responsável e o resumo por time (com a reserva do
     * Bombeiro descontada do Full stack depois de classificar os times).
     *
     * @param  list<string>  $holidays
     * @return array{sprint: int, dates: ?array<string, mixed>, reference: string, developers: list<array<string, mixed>>, unassigned: list<array<string, mixed>>, teams: array{total: array{known: int, available: int}, fullstack: array{known: int, available: int, reserve: int}, projects: array{known: int, available: int}}}
     */
    public static function build(MetricsData $data, int $sprint, ?string $reference = null, array $holidays = []): array
    {
        $reference ??= $data->today();
        $dates = $data->datesOf($sprint);
        $result = self::model($data->rows, $sprint, $dates, $data->capacityOverrides, $reference, $holidays, $data->settings);

        return [
            'sprint' => $sprint,
            'dates' => $dates,
            'reference' => $reference,
            'developers' => $result['developers'],
            'unassigned' => $result['unassigned'],
            'teams' => self::teamForecast($result['developers'], $data->supportReserveMinutes($sprint), $dates, $reference, $data->settings),
        ];
    }

    /**
     * Duração em minutos a partir do texto do Monday ("1h30", "90m", "1:30", "1,5"); null quando ausente ou inválida.
     */
    public static function duration(mixed $value): ?int
    {
        if ($value === null || trim(self::text($value)) === '') {
            return null;
        }
        $text = mb_strtolower(trim(self::text($value)));
        if (preg_match('/^(\d+)h(?:\s*(\d{1,2})m?)?$/', $text, $match)) {
            $extra = (int) ($match[2] ?? 0);

            return $extra < 60 ? (int) $match[1] * 60 + $extra : null;
        }
        if (preg_match('/^(\d+)m$/', $text, $match)) {
            return (int) $match[1];
        }
        if (preg_match('/^(\d+):(\d{2})$/', $text, $match)) {
            return (int) $match[2] < 60 ? (int) $match[1] * 60 + (int) $match[2] : null;
        }
        $hours = str_replace(',', '.', $text);
        if (! is_numeric($hours) || (float) $hours < 0) {
            return null;
        }

        return self::round((float) $hours * 60);
    }

    /** Minutos em texto, como a tela: "1h 30min"; null vira "Não definido". */
    public static function format(int|float|null $value): string
    {
        if ($value === null) {
            return 'Não definido';
        }
        $minutes = abs(self::round($value));

        return intdiv($minutes, 60).'h'.($minutes % 60 ? ' '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'min' : '');
    }

    /**
     * Dias úteis (segunda a sexta, sem os feriados informados); null quando as datas são inválidas.
     *
     * @param  list<string>  $holidays
     * @return list<string>|null
     */
    public static function workingDays(?string $start, ?string $end, array $holidays = []): ?array
    {
        if (! self::dateValid($start) || ! self::dateValid($end) || $start > $end) {
            return null;
        }
        $days = [];
        for ($day = strtotime($start.' 00:00:00 UTC'), $last = strtotime($end.' 00:00:00 UTC'); $day <= $last; $day += 86400) {
            $key = gmdate('Y-m-d', $day);
            $weekday = (int) gmdate('N', $day);
            if ($weekday <= 5 && ! in_array($key, $holidays, true)) {
                $days[] = $key;
            }
        }

        return $days;
    }

    /**
     * Capacidade que resta depois do dia de referência (o dia todo fica de fora), proporcional aos dias úteis futuros.
     *
     * @param  array<string, mixed>|null  $dates
     * @param  list<string>  $holidays
     * @return array{remaining: ?int, days: ?int, totalDays: ?int}
     */
    public static function capacity(?int $total, ?array $dates, ?string $reference, array $holidays = []): array
    {
        $all = self::workingDays($dates['start'] ?? null, $dates['end'] ?? null, $holidays);
        if ($all === null || ! self::dateValid($reference) || $total === null) {
            return ['remaining' => null, 'days' => null, 'totalDays' => null];
        }
        $future = count(array_filter($all, fn (string $day): bool => $day > $reference));

        return ['remaining' => max(0, self::round($all ? $total * $future / count($all) : 0)), 'days' => $future, 'totalDays' => count($all)];
    }

    /**
     * Situação da pessoa: sem demandas, sem previsão, acima, no limite (90% a 100%), dentro (50% a menos de 90%) ou
     * baixa carga (menos de 50%).
     */
    public static function classify(int|float $known, int|float|null $available, int $missing, int $count): string
    {
        if (! $count) {
            return 'empty';
        }
        if ($available === null) {
            return 'unknown';
        }
        if ($known > $available) {
            return 'over';
        }
        if ($missing || $available == 0) {
            return 'unknown';
        }
        $percent = $known / $available * 100;

        return $percent < 50 ? 'low' : ($percent < 90 ? 'within' : 'limit');
    }

    /**
     * Saldo em texto: folga, excesso ou revisar estimativas.
     *
     * @param  array{status: string, known: int|float, available: int|float|null, missing: int}  $developer
     */
    public static function balance(array $developer): string
    {
        if ($developer['status'] === 'empty') {
            return 'Sem demandas abertas';
        }
        if ($developer['available'] !== null && $developer['known'] > $developer['available']) {
            return ($developer['missing'] ? 'pelo menos ' : '').self::format($developer['known'] - $developer['available']).' a mais';
        }
        if ($developer['missing'] || $developer['available'] === null) {
            return 'Revisar estimativas';
        }

        return $developer['available'] == $developer['known'] ? 'Sem folga' : self::format($developer['available'] - $developer['known']).' de folga';
    }

    /**
     * Previsão por pessoa (só Full stack e Projetos), com as tarefas abertas e a origem de cada esforço restante.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $dates
     * @param  array<string, int>  $overrides
     * @param  list<string>  $holidays
     * @param  array<string, mixed>  $settings
     * @return array{developers: list<array{name: string, team: ?string, tasks: list<array{row: array<string, mixed>, estimate: ?int, real: ?int, remainingEffort: ?int, source: string, issue: string, reasons: list<string>}>, total: int, calendar: array{remaining: ?int, days: ?int, totalDays: ?int}, known: int, missing: int, available: ?int, status: string}>, unassigned: list<array<string, mixed>>}
     */
    public static function model(array $rows, int $sprint, ?array $dates, array $overrides, string $reference, array $holidays = [], array $settings = []): array
    {
        $teams = self::teams($settings);
        $seenSprints = $unique = $groups = [];
        $unassigned = [];
        foreach ($rows as $index => $row) {
            $id = self::id($row);
            if ($id !== null && empty($row['AsOf'])) {
                $seenSprints[$id][(int) $row['Sprint']] = true;
            }
            if ((int) ($row['Sprint'] ?? 0) === $sprint) {
                $unique[$id ?? "snapshot-{$index}"] = $row;
            }
        }
        foreach ($unique as $row) {
            // Só Full stack e Projetos têm previsão individual; card só de quem está sem time fica fora.
            $named = self::owners($row);
            $people = array_values(array_filter($named, fn (string $name): bool => $teams->teamOf($name) !== null));
            $done = ! SprintRules::isOpen($row['Status'] ?? null);
            if (! $named && ! $done) {
                $unassigned[] = $row;
            }
            foreach ($people as $name) {
                $groups[$name] ??= [];
                if ($done) {
                    continue;
                }
                $groups[$name][] = self::task($row, count($people), $seenSprints);
            }
        }
        $known = array_map([SprintRules::class, 'normalize'], array_map('strval', array_keys($groups)));
        foreach ($teams->members() as $member) {
            if (! in_array(SprintRules::normalize($member['name']), $known, true)) {
                $groups[$member['name']] = [];
            }
        }
        $developers = [];
        foreach ($groups as $name => $tasks) {
            $name = (string) $name;
            $total = $teams->capacityMinutes($overrides, $sprint, $name);
            $calendar = self::capacity($total, $dates, $reference, $holidays);
            $sum = (int) array_sum(array_map(fn (array $task): int => $task['remainingEffort'] ?? 0, $tasks));
            $missing = count(array_filter($tasks, fn (array $task): bool => $task['remainingEffort'] === null));
            $available = $calendar['remaining'];
            $developers[] = ['name' => $name, 'team' => $teams->teamOf($name), 'tasks' => $tasks, 'total' => $total, 'calendar' => $calendar,
                'known' => $sum, 'missing' => $missing, 'available' => $available, 'status' => self::classify($sum, $available, $missing, count($tasks))];
        }
        $collator = class_exists(Collator::class) ? new Collator('pt_BR') : null;
        usort($developers, fn (array $a, array $b): int => (self::RANK[$a['status']] <=> self::RANK[$b['status']])
            ?: (($b['known'] - ($b['available'] ?? $b['known'])) <=> ($a['known'] - ($a['available'] ?? $a['known'])))
            ?: ($collator ? (int) $collator->compare($a['name'], $b['name']) : strcasecmp($a['name'], $b['name'])));

        return ['developers' => $developers, 'unassigned' => $unassigned];
    }

    /**
     * Resumo por time: total do time; Full stack com a parte futura da reserva do Bombeiro descontada (só depois de
     * classificar os times); Projetos à parte.
     *
     * @param  list<array{team?: ?string, known?: int|float|null, available?: int|float|null}>  $developers
     * @param  array<string, mixed>|null  $dates
     * @param  array<string, mixed>  $settings
     * @return array{total: array{known: int, available: int}, fullstack: array{known: int, available: int, reserve: int}, projects: array{known: int, available: int}}
     */
    public static function teamForecast(array $developers, int $reserveMinutes, ?array $dates, string $reference, array $settings = []): array
    {
        $sum = fn (array $list, string $key): int => (int) array_sum(array_map(fn (array $dev): int|float => $dev[$key] ?? 0, $list));
        $of = fn (string $team): array => array_values(array_filter($developers, fn (array $dev): bool => ($dev['team'] ?? null) === $team));
        $reserve = self::teams($settings)->configured() ? (self::capacity($reserveMinutes, $dates, $reference)['remaining'] ?? 0) : 0;

        return [
            'total' => ['known' => $sum($developers, 'known'), 'available' => $sum($developers, 'available')],
            'fullstack' => ['known' => $sum($of(TeamRules::FULLSTACK), 'known'), 'available' => max(0, $sum($of(TeamRules::FULLSTACK), 'available') - $reserve), 'reserve' => $reserve],
            'projects' => ['known' => $sum($of(TeamRules::PROJECTS), 'known'), 'available' => $sum($of(TeamRules::PROJECTS), 'available')],
        ];
    }

    /**
     * Impedimento do card: o texto da coluna Impedimento ou um status de bloqueio.
     *
     * @param  array<string, mixed>  $row
     */
    public static function blocker(array $row): string
    {
        $text = trim(self::text($row['Impedimento'] ?? ''));
        $normalize = fn (mixed $value): string => self::stripAccents(mb_strtolower(trim(self::text($value))));
        if ($text !== '' && ! in_array($normalize($text), ['nao', 'nenhum', 'sem impedimento', 'sem bloqueio', 'false', '0', 'desbloqueado'], true)) {
            return $text;
        }

        return in_array($normalize($row['Status'] ?? ''), ['parado', 'bloqueado', 'impedido', 'limitacao externa'], true) ? 'Status: '.$row['Status'] : '';
    }

    /**
     * Tarefa aberta de uma pessoa com o esforço restante e a origem dele, ou o motivo de revisão.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, array<int, true>>  $seenSprints
     * @return array{row: array<string, mixed>, estimate: ?int, real: ?int, remainingEffort: ?int, source: string, issue: string, reasons: list<string>}
     */
    private static function task(array $row, int $peopleCount, array $seenSprints): array
    {
        $estimate = self::duration($row['EstH'] ?? null);
        $real = self::duration($row['RealH'] ?? null);
        $revised = self::duration($row['RemainingH'] ?? null);
        $id = self::id($row);
        $multipleSprints = $id !== null && count($seenSprints[$id] ?? []) > 1;
        $reasons = [];
        if ($peopleCount > 1) {
            $reasons[] = 'Atribuição compartilhada: o Monday não fornece a parcela por pessoa; esforço fora do total até corrigir a atribuição na origem.';
        }
        if ($multipleSprints) {
            $reasons[] = 'Card em várias sprints: tempo acumulado não separado por sprint. Corrija o escopo e o esforço restante no Monday.';
        }
        $remaining = null;
        $source = $issue = '';
        if ($peopleCount > 1 || $multipleSprints) {
            $issue = 'Revisar esforço restante e atribuição no Monday';
        } elseif ($revised !== null) {
            [$remaining, $source] = [$revised, 'Esforço restante revisado no Monday'];
        } elseif ($estimate === null || $estimate <= 0) {
            $issue = 'Sem estimativa válida';
        } elseif (trim(self::text($row['Status'] ?? '')) === '') {
            $issue = 'Status ausente: revisar esforço restante';
        } elseif ($real !== null && $real >= $estimate) {
            $issue = 'Revisar esforço restante: estimativa consumida';
        } elseif (SprintRules::isNotStarted($row['Status'] ?? null)) {
            [$remaining, $source] = [$estimate, 'Estimativa total · tarefa não iniciada'];
        } elseif ($real === null || $id === null) {
            $issue = 'Tempo real ausente ou não auditável em tarefa em andamento';
        } else {
            [$remaining, $source] = [$estimate - $real, 'Calculada provisoriamente: estimativa − tempo real; não mede progresso exato'];
        }

        return ['row' => $row, 'estimate' => $estimate, 'real' => $real, 'remainingEffort' => $remaining, 'source' => $source, 'issue' => $issue, 'reasons' => $reasons];
    }

    /**
     * Responsáveis do card, sem repetição.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private static function owners(array $row): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', self::text($row['Responsavel'] ?? ''))), 'strlen')));
    }

    /**
     * Times com os padrões da tela (SprintRules.settings), descartando membros sem time válido.
     *
     * @param  array<string, mixed>  $settings
     */
    private static function teams(array $settings): TeamRules
    {
        return new TeamRules([
            'capacityHours' => $settings['capacityHours'] ?? 80,
            'excludedPeople' => array_values($settings['excludedPeople'] ?? []),
            'team' => array_values(array_filter($settings['team'] ?? [], fn (mixed $member): bool => is_array($member) && in_array($member['group'] ?? null, TeamRules::GROUPS, true))),
        ]);
    }

    /** @param  array<string, mixed>  $row */
    private static function id(array $row): ?string
    {
        $id = $row['Id'] ?? null;

        return $id === null || $id === '' || $id === false || $id === 0 ? null : (string) $id;
    }

    private static function dateValid(?string $value): bool
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /** Arredondamento do Math.round do JavaScript (meio para cima). */
    private static function round(int|float $value): int
    {
        return (int) floor($value + 0.5);
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) => (string) ($value == (int) $value ? (int) $value : $value),
            default => (string) $value,
        };
    }

    private static function stripAccents(string $value): string
    {
        return strtr($value, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
    }
}
