<?php

namespace App\Metrics;

use App\Support\SprintRules;

/**
 * Daily Scrum (public/daily.js) no servidor, com os mesmos números da tela: colunas do Kanban, horas e selos de cada
 * card, o que falta nos cards abertos, a tendência (da pessoa e da sprint, com a regra da Torre de controle), as horas
 * da pessoa e o placar de XP.
 *
 * Regras (as mesmas da tela):
 * - Card aberto: real ÷ estimado; a partir de 80% "perto do limite", acima de 100% "estourou".
 * - Card finalizado: até 80% do estimado "adiantado", até 100% "no alvo", acima "acima do estimado".
 * - Tendência: horas que faltam nos cards abertos contra a capacidade que resta (capacidade × dias úteis restantes,
 *   contando hoje, ÷ dias úteis da sprint): abaixo de 90% no prazo, até 100% apertado, acima risco de atraso.
 * - XP: cada entrega vale 10; +5 no alvo; +8 adiantada.
 *
 * @phpstan-type CardHours array{estimate: ?int, real: int, ratio: ?float, level: string, badge: string, points: int}
 * @phpstan-type Trend array{level: string, label: string, remaining: int, available: float|int, left: int, total: int, detail: string, projects?: array<string, mixed>}
 * @phpstan-type Score array{delivered: int, onTarget: int, early: int, xp: int, precision: ?int}
 */
final class Daily
{
    /** Colunas do Kanban: o backlog (Próximos) primeiro, depois o que trava, o que anda, o que está em QA e o que terminou. */
    public const COLUMNS = ['pending' => '⏭️ Próximos', 'blocked' => '🔴 Impedidos', 'progress' => '🔵 Em andamento', 'qa' => '🧪 Em QA', 'done' => '✅ Finalizados'];

    private const PENDING_EXTRA = ['pendente', 'refinar', 'falta escopo', 'escopo tecnico', 'prox. sprint'];

    private const TREND_LABELS = ['green' => '🟢 No prazo', 'yellow' => '🟡 Apertado', 'red' => '🔴 Risco de atraso'];

    private readonly Capacity $capacity;

    public function __construct(private readonly MetricsData $data, ?Capacity $capacity = null)
    {
        $this->capacity = $capacity ?? Capacity::for($data);
    }

    /**
     * Status real do Monday → coluna. Em QA são os status de QA; Finalizado é só Implementado (Aguardando Deploy segue
     * em andamento); Pendente Samax conta como impedido; cancelado, descontinuado e despriorizado ficam fora (null).
     */
    public static function column(?string $status): ?string
    {
        $value = SprintRules::normalize($status);
        if (SprintRules::isRemoved($status)) {
            return null;
        }
        if (SprintRules::isBlocked($status) || $value === 'pendente samax') {
            return 'blocked';
        }
        if (SprintRules::isDone($status)) {
            return 'done';
        }
        if (SprintRules::isInTest($status)) {
            return 'qa';
        }
        if (SprintRules::isNotStarted($status) || in_array($value, self::PENDING_EXTRA, true)) {
            return 'pending';
        }

        return 'progress';
    }

    /**
     * Indicador de horas de um card (minutos): real × estimado, o selo da entrega ou do risco e os pontos de XP.
     *
     * @param  array<string, mixed>  $row
     * @return CardHours
     */
    public static function cardHours(array $row): array
    {
        $estimate = Capacity::minutes($row['EstH'] ?? null);
        $real = Capacity::minutes($row['RealH'] ?? null) ?? 0;
        $done = self::column($row['Status'] ?? null) === 'done';
        if ($estimate === null || $estimate <= 0) {
            return ['estimate' => $estimate, 'real' => $real, 'ratio' => null, 'level' => 'none', 'badge' => 'sem estimativa', 'points' => $done ? 10 : 0];
        }
        $ratio = $real / $estimate;
        if ($done) {
            if ($ratio <= .8) {
                return ['estimate' => $estimate, 'real' => $real, 'ratio' => $ratio, 'level' => 'great', 'badge' => '⚡ adiantado', 'points' => 18];
            }
            if ($ratio <= 1) {
                return ['estimate' => $estimate, 'real' => $real, 'ratio' => $ratio, 'level' => 'good', 'badge' => '🎯 no alvo', 'points' => 15];
            }

            return ['estimate' => $estimate, 'real' => $real, 'ratio' => $ratio, 'level' => 'over', 'badge' => '🐢 +'.SprintRules::round(($ratio - 1) * 100).'% do estimado', 'points' => 10];
        }
        if ($ratio > 1) {
            return ['estimate' => $estimate, 'real' => $real, 'ratio' => $ratio, 'level' => 'over', 'badge' => '🔥 estourou +'.self::hours($real - $estimate), 'points' => 0];
        }
        if ($ratio >= .8) {
            return ['estimate' => $estimate, 'real' => $real, 'ratio' => $ratio, 'level' => 'warn', 'badge' => '⚠️ perto do limite', 'points' => 0];
        }

        return ['estimate' => $estimate, 'real' => $real, 'ratio' => $ratio, 'level' => 'ok', 'badge' => '', 'points' => 0];
    }

    /**
     * Minutos que ainda faltam num card aberto, como na Torre de controle: o Restante do Monday, se preenchido; senão,
     * não iniciado = estimado inteiro e os demais = estimado − real. Entregue, Aguardando Deploy, QA - Aprovado e
     * retirados = 0.
     *
     * @param  array<string, mixed>  $row
     */
    public static function remainingOf(array $row): int
    {
        if (! SprintRules::isOpen($row['Status'] ?? null)) {
            return 0;
        }
        $revised = Capacity::minutes($row['RemainingH'] ?? null);
        if ($revised !== null) {
            return max(0, $revised);
        }
        $estimate = Capacity::minutes($row['EstH'] ?? null);
        if ($estimate === null || $estimate <= 0) {
            return 0;
        }

        return SprintRules::isNotStarted($row['Status'] ?? null) ? $estimate : max(0, $estimate - (Capacity::minutes($row['RealH'] ?? null) ?? 0));
    }

    /**
     * Tendência: o que falta contra a capacidade que resta na sprint (contando hoje). Mesmas faixas da Torre de controle.
     *
     * @param  array{start?: ?string, end?: ?string}|null  $dates
     * @return Trend
     */
    public static function trend(int|float $remaining, int|float $capacity, ?array $dates, string $today): array
    {
        $days = $dates ? SprintRules::workingDays($dates['start'] ?? null, $dates['end'] ?? null) : [];
        $left = count(array_filter($days, fn (string $date): bool => $date >= $today));
        $available = $days ? $capacity * $left / count($days) : 0;
        $ratio = $available > 0 ? $remaining / $available : ($remaining > 0 ? INF : 0);
        $level = $ratio < .9 ? 'green' : ($ratio <= 1 ? 'yellow' : 'red');

        return ['level' => $level, 'label' => self::TREND_LABELS[$level], 'remaining' => (int) $remaining, 'available' => $available, 'left' => $left, 'total' => count($days),
            'detail' => 'faltam '.self::hours($remaining).' nos cards abertos para '.self::hours($available)." de capacidade nos {$left} dia(s) útil(eis) restantes"];
    }

    /**
     * Tendência da sprint com a regra do sinal "Capacidade restante" da Torre de controle: o Full stack (cards que não
     * são só de Projetos, inclusive sem responsável) contra a capacidade Full stack descontada a reserva do Bombeiro;
     * Projetos aparece à parte. Antes de classificar os times, todos contam como Full stack, sem desconto.
     *
     * @param  list<array<string, mixed>>  $cards
     * @param  list<array<string, mixed>>  $developers
     * @param  array{start?: ?string, end?: ?string}|null  $dates
     * @return Trend
     */
    public function sprintTrend(array $cards, array $developers, int $sprint, ?array $dates, string $today): array
    {
        $configured = $this->capacity->teamsConfigured();
        $reserve = $configured ? HoursStrip::reserveFor($this->data, $sprint) : 0;
        $defaults = ['team' => null, 'capacity' => 0, 'totalEstimate' => 0, 'supportAllEstimate' => 0, 'registered' => 0, 'supportAllReal' => 0];
        $totals = $this->capacity->teamTotals(array_map(fn (array $dev): array => [...$defaults, ...$dev], $developers), $reserve);
        $projectsOnly = function (array $row): bool {
            $teams = array_map(fn (string $name): ?string => $this->capacity->teamOf($name), Capacity::people($row['Responsavel'] ?? ''));

            return in_array('projects', $teams, true) && ! in_array('fullstack', $teams, true);
        };
        $remaining = fn (array $list): int => array_sum(array_map(self::remainingOf(...), $list));
        $fullstack = self::trend($remaining(array_filter($cards, fn (array $row): bool => ! $projectsOnly($row))), $totals['fullstack']['net'], $dates, $today);
        if (! $configured) {
            return [...$fullstack, 'detail' => "{$fullstack['detail']} (times ainda não classificados: todos contam como Full stack, sem desconto do Bombeiro)"];
        }
        $projects = self::trend($remaining(array_filter($cards, $projectsOnly)), $totals['projects']['capacity'], $dates, $today);
        $percent = $fullstack['available'] > 0 ? ' · '.SprintRules::round($fullstack['remaining'] / $fullstack['available'] * 100).'%' : '';

        return [...$fullstack, 'projects' => $projects,
            'detail' => 'Full stack: faltam '.self::hours($fullstack['remaining']).' para '.self::hours($fullstack['available']).' de capacidade ('.self::hours($totals['fullstack']['capacity']).' − Bombeiro '.self::hours($reserve).") nos {$fullstack['left']} dia(s) útil(eis) restantes{$percent}. Projetos: faltam ".self::hours($projects['remaining']).' para '.self::hours($projects['available']).'.'];
    }

    /**
     * Horas da pessoa na sprint, com os mesmos cards do Capacity, numa conta que fecha a partir da capacidade:
     * planejado (% da capacidade), executado (% do planejado), pendente = planejado − executado (% do planejado) e saldo
     * disponível = capacidade − planejado (% da capacidade; negativo = planejado acima da capacidade).
     *
     * @param  array<string, mixed>  $dev  pessoa do Capacity::model (rows, capacity, totalEstimate, registered)
     * @param  array{start?: ?string, end?: ?string}|null  $dates
     * @return array{pending: int, overrun: int, balance: int, trend: Trend, percent: array{planned: ?int, executed: ?int, pending: ?int, balance: ?int}}
     */
    public static function personHours(array $dev, ?array $dates, string $today): array
    {
        $capacity = (int) ($dev['capacity'] ?? 0);
        $planned = (int) ($dev['totalEstimate'] ?? 0);
        $registered = (int) ($dev['registered'] ?? 0);
        $personTrend = self::trend(array_sum(array_map(self::remainingOf(...), $dev['rows'] ?? [])), $capacity, $dates, $today);
        $ofCapacity = fn (int $value): ?int => $capacity > 0 ? SprintRules::round($value / $capacity * 100) : null;
        $ofPlanned = fn (int $value): ?int => $planned > 0 ? SprintRules::round($value / $planned * 100) : null;
        $pending = max(0, $planned - $registered);
        $balance = $capacity - $planned;

        return ['pending' => $pending, 'overrun' => max(0, $registered - $planned), 'balance' => $balance, 'trend' => $personTrend,
            'percent' => ['planned' => $ofCapacity($planned), 'executed' => $ofPlanned($registered), 'pending' => $ofPlanned($pending), 'balance' => $ofCapacity($balance)]];
    }

    /**
     * Placar de entregas de um conjunto de cards: entregues, no alvo, adiantadas, XP e precisão (% no alvo ou adiantadas).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return Score
     */
    public static function score(array $rows): array
    {
        $delivered = array_map(self::cardHours(...), array_values(array_filter($rows, fn (array $row): bool => self::column($row['Status'] ?? null) === 'done')));
        $onTarget = count(array_filter($delivered, fn (array $item): bool => in_array($item['level'], ['good', 'great'], true)));

        return [
            'delivered' => count($delivered),
            'onTarget' => $onTarget,
            'early' => count(array_filter($delivered, fn (array $item): bool => $item['level'] === 'great')),
            'xp' => array_sum(array_column($delivered, 'points')),
            'precision' => $delivered ? SprintRules::round($onTarget / count($delivered) * 100) : null,
        ];
    }

    /**
     * Cards da sprint (um por Id), sem os retirados do escopo e sem as linhas congeladas de outras datas.
     *
     * @return list<array<string, mixed>>
     */
    public function sprintCards(int $sprint): array
    {
        $unique = [];
        foreach ($this->data->rows as $row) {
            if ((int) ($row['Sprint'] ?? 0) !== $sprint || ! empty($row['AsOf'])) {
                continue;
            }
            $key = ($row['Id'] ?? '') !== '' && ($row['Id'] ?? null) !== null ? (string) $row['Id'] : ($row['Nome'] ?? 'undefined').'|'.count($unique);
            $unique[$key] ??= $row;
        }

        return array_values(array_filter($unique, fn (array $row): bool => self::column($row['Status'] ?? null) !== null));
    }

    /** Pessoa responsável pelo card (card com vários responsáveis conta para cada um). */
    public static function ownedBy(array $row, string $name): bool
    {
        $target = SprintRules::normalize($name);

        return array_any(Capacity::people($row['Responsavel'] ?? ''), fn (string $person): bool => SprintRules::normalize($person) === $target);
    }

    /**
     * Os números da tela Daily da sprint: o indicador macro (cards por coluna, horas do time, tendência e XP) e, por
     * pessoa (quem tem capacidade ou cards na sprint), as horas, a tendência, o XP, a conquista e os cards por coluna.
     *
     * @return array<string, mixed>
     */
    public function sprint(int $sprint): array
    {
        $dates = $this->data->datesOf($sprint);
        $today = $this->data->today();
        $model = $this->capacity->model($this->data->rows, $sprint, $dates, $this->data->capacityOverrides, $today);
        $cards = $this->sprintCards($sprint);
        $developers = array_values(array_filter($model['developers'], fn (array $dev): bool => $dev['capacity'] > 0
            || array_any($cards, fn (array $row): bool => self::ownedBy($row, $dev['name']))));
        $countBy = fn (array $rows): array => array_map(fn (string $key): int => count(array_filter($rows, fn (array $row): bool => self::column($row['Status'] ?? null) === $key)), array_combine(array_keys(self::COLUMNS), array_keys(self::COLUMNS)));
        $score = self::score($cards);

        return [
            'sprint' => $sprint,
            'dates' => $dates,
            'today' => $today,
            'cards' => count($cards),
            'columns' => $countBy($cards),
            'hours' => [
                'capacity' => array_sum(array_column($developers, 'capacity')),
                'planned' => array_sum(array_column($developers, 'totalEstimate')),
                'registered' => array_sum(array_column($developers, 'registered')),
            ],
            'trend' => $this->sprintTrend($cards, $developers, $sprint, $dates, $today),
            'score' => $score,
            'people' => array_map(function (array $dev) use ($cards, $dates, $today, $countBy): array {
                $mine = array_values(array_filter($cards, fn (array $row): bool => self::ownedBy($row, $dev['name'])));
                $personScore = self::score($mine);

                return [
                    'name' => $dev['name'],
                    'team' => $dev['team'],
                    'inheritedFrom' => $dev['inheritedFrom'],
                    'capacity' => $dev['capacity'],
                    'planned' => $dev['totalEstimate'],
                    'executed' => $dev['registered'],
                    'level' => SprintRules::capacityLevel($dev['totalEstimate'], $dev['capacity']),
                    'hours' => self::personHours($dev, $dates, $today),
                    'burning' => count(array_filter($mine, fn (array $row): bool => self::cardHours($row)['level'] === 'over' && self::column($row['Status'] ?? null) !== 'done')),
                    'blocked' => count(array_filter($mine, fn (array $row): bool => self::column($row['Status'] ?? null) === 'blocked')),
                    'score' => $personScore,
                    'achievement' => $personScore['delivered'] > 0 && $personScore['precision'] >= 80,
                    'columns' => $countBy($mine),
                ];
            }, $developers),
        ];
    }

    /** Minutos em horas, como a tela (nunca negativo). */
    private static function hours(int|float $minutes): string
    {
        return Capacity::formatMinutes(max(0, SprintRules::round($minutes)));
    }
}
