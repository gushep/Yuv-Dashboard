<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Definições únicas dos indicadores, espelho de public/sprint-rules.js.
 * Concluído é somente "Implementado"; cancelados, descontinuados e despriorizados saem do escopo.
 */
class SprintRules
{
    public const TIMEZONE = 'America/Sao_Paulo';

    private const DONE = ['implementado'];

    private const DONE_NOT_DELIVERED = ['aguardando deploy', 'qa - aprovado'];

    private const REMOVED = ['cancelado', 'descontinuado', 'despriorizado'];

    private const BLOCKED = ['parado', 'bloqueado', 'impedido', 'limitacao externa'];

    /** Sem status também conta como não iniciado: ninguém começou o card. */
    private const NOT_STARTED = ['', 'backlog', 'pronto pra iniciar', 'pronto para iniciar', 'pronto pra comecar', 'pronto para comecar'];

    public static function normalize(?string $value): string
    {
        $text = mb_strtolower(trim((string) $value));
        $text = strtr($text, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);

        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    public static function isDone(?string $status): bool
    {
        return in_array(self::normalize($status), self::DONE, true);
    }

    public static function isDoneNotDelivered(?string $status): bool
    {
        return in_array(self::normalize($status), self::DONE_NOT_DELIVERED, true);
    }

    public static function isRemoved(?string $status): bool
    {
        return in_array(self::normalize($status), self::REMOVED, true);
    }

    /** Ainda exige trabalho do time nesta sprint. */
    public static function isOpen(?string $status): bool
    {
        return ! self::isDone($status) && ! self::isDoneNotDelivered($status) && ! self::isRemoved($status);
    }

    public static function isBlocked(?string $status): bool
    {
        return in_array(self::normalize($status), self::BLOCKED, true);
    }

    public static function isNotStarted(?string $status): bool
    {
        return in_array(self::normalize($status), self::NOT_STARTED, true);
    }

    /** Status de QA (QA - Testes, QA - Reprovado, QA - Aprovado). */
    public static function isInTest(?string $status): bool
    {
        return (bool) preg_match('/^qa\b/', self::normalize($status));
    }

    /**
     * Etapa dos painéis do Bombeiro e da Sprint, espelho de SprintPanel.statusBucket.
     *
     * @return 'backlog'|'progress'|'test'|'done'
     */
    public static function stage(?string $status): string
    {
        return match (true) {
            self::isDone($status) || self::isRemoved($status) => 'done',
            self::isNotStarted($status) => 'backlog',
            self::isInTest($status) => 'test',
            default => 'progress',
        };
    }

    /**
     * Origem do card na sprint: suporte prevalece sobre a coluna Planejada, pois é medido pela reserva de suporte.
     *
     * @return 'planned'|'unplanned'|'support'
     */
    public static function category(?string $type, ?string $planned): string
    {
        if (self::normalize($type) === 'suporte') {
            return 'support';
        }

        return self::normalize($planned) === 'planejada' ? 'planned' : 'unplanned';
    }

    /**
     * Dias úteis (segunda a sexta) entre duas datas, inclusive.
     *
     * @return list<string> datas AAAA-MM-DD
     */
    public static function workingDays(?string $start, ?string $end): array
    {
        if (! $start || ! $end || $start > $end) {
            return [];
        }
        $days = [];
        for ($day = CarbonImmutable::parse($start); $day->toDateString() <= $end; $day = $day->addDay()) {
            if ($day->isWeekday()) {
                $days[] = $day->toDateString();
            }
        }

        return $days;
    }

    public static function today(): string
    {
        return CarbonImmutable::now(self::TIMEZONE)->toDateString();
    }

    /** Fim do dia (23:59:59.999999) em São Paulo, convertido para UTC. */
    public static function endOfDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::TIMEZONE)->endOfDay()->utc();
    }

    /** Prioridade para ordenação: Crítica primeiro, sem prioridade por último. */
    public static function priorityRank(?string $priority): int
    {
        return ['critica' => 0, 'alta' => 1, 'media' => 2, 'baixa' => 3][self::normalize($priority)] ?? 4;
    }

    /** Amarelo do semáforo de capacidade a partir de 90% (o mesmo limite da Torre de Controle). */
    public const CAPACITY_YELLOW = 90;

    /** @var array{green: string, yellow: string, red: string} */
    public const LEVEL_TEXT = [
        'green' => 'Verde: bastante saldo (abaixo de 90% da capacidade)',
        'yellow' => 'Amarelo: perto do limite (de 90% a menos de 100%)',
        'red' => 'Vermelho: chegou ou passou da capacidade (100% ou mais)',
    ];

    /** Encerrado nos kanbans: entregue ou retirado do escopo. */
    public static function isClosed(?string $status): bool
    {
        return self::isDone($status) || self::isRemoved($status);
    }

    /**
     * Arredondamento igual ao Math.round do JavaScript (metade sempre para cima, inclusive nos negativos), para os
     * cálculos do servidor darem os mesmos números das telas.
     */
    public static function round(float|int $value): int
    {
        return (int) floor($value + 0.5);
    }

    /**
     * Times com as configurações completas, como o applySettings do sprint-rules.js: valores ausentes viram o padrão
     * e pessoas classificadas num time desconhecido ficam sem time.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function teamRules(array $settings): TeamRules
    {
        $team = array_values(array_filter($settings['team'] ?? [], fn (mixed $member): bool => is_array($member) && in_array($member['group'] ?? null, TeamRules::GROUPS, true)));
        $hours = $settings['capacityHours'] ?? 80;

        return new TeamRules([
            'capacityHours' => is_numeric($hours) && $hours >= 0 ? (float) $hours : 80,
            'excludedPeople' => array_values(array_filter(array_map('strval', $settings['excludedPeople'] ?? []), 'strlen')),
            'team' => array_map(fn (array $member): array => ['id' => (string) ($member['id'] ?? ''), 'name' => (string) $member['name'], 'group' => $member['group']], $team),
        ]);
    }

    /**
     * Capacidade da pessoa na sprint, em minutos: a salva nesta sprint; sem ela, a última salva numa sprint anterior
     * (herança); sem nenhuma, o padrão das Configurações. Espelho de SprintRules.capacityFor.
     *
     * @param  array<string, mixed>  $overrides  chave "sprint|nome"
     * @return array{minutes: int, inherited: bool, fromSprint: ?int}
     */
    public static function capacityFor(array $overrides, int $sprint, string $name, int $defaultMinutes): array
    {
        $target = self::normalize($name);
        $bestSprint = null;
        $value = null;
        foreach ($overrides as $key => $minutes) {
            $split = strpos((string) $key, '|');
            if ($split === false || ! is_int($minutes)) {
                continue;
            }
            $keySprint = substr((string) $key, 0, $split);
            if (! is_numeric($keySprint) || (float) $keySprint > $sprint || self::normalize(substr((string) $key, $split + 1)) !== $target) {
                continue;
            }
            if ($bestSprint === null || (int) $keySprint > $bestSprint) {
                $bestSprint = (int) $keySprint;
                $value = $minutes;
            }
        }

        return ['minutes' => $value ?? $defaultMinutes, 'inherited' => $bestSprint !== null && $bestSprint !== $sprint, 'fromSprint' => $bestSprint];
    }

    /**
     * Projeta o consumo de um orçamento (reserva ou capacidade) mantendo o ritmo médio por dia útil já transcorrido.
     * Hoje conta como dia útil completo. Espelho de SprintRules.pace.
     *
     * @param  array{start?: ?string, end?: ?string}|null  $dates
     * @return array{status: 'unavailable'}|array{status: 'not-started', totalDays: int}|array{status: 'ongoing'|'finished', elapsedDays: int, totalDays: int, daily: float, projected: int, projectedPercent: ?float, exhaustionDate: ?string, sprintEnd: string}
     */
    public static function pace(float|int $consumedMinutes, ?array $dates, ?string $today, float|int $budgetMinutes): array
    {
        $all = self::validDate($dates['start'] ?? null) && self::validDate($dates['end'] ?? null) ? self::workingDays($dates['start'], $dates['end']) : [];
        if ($all === [] || ! $today) {
            return ['status' => 'unavailable'];
        }
        $elapsed = count(array_filter($all, fn (string $day): bool => $day <= $today));
        if ($elapsed === 0) {
            return ['status' => 'not-started', 'totalDays' => count($all)];
        }
        $daily = $consumedMinutes / $elapsed;
        $projected = self::round($daily * count($all));
        $exhaustionIndex = $budgetMinutes > 0 && $daily > 0 ? (int) ceil($budgetMinutes / $daily) : null;

        return [
            'status' => $elapsed >= count($all) ? 'finished' : 'ongoing',
            'elapsedDays' => $elapsed,
            'totalDays' => count($all),
            'daily' => $daily,
            'projected' => $projected,
            'projectedPercent' => $budgetMinutes > 0 ? $projected / $budgetMinutes * 100 : null,
            'exhaustionDate' => $exhaustionIndex !== null && $exhaustionIndex <= count($all) ? $all[$exhaustionIndex - 1] : null,
            'sprintEnd' => $all[count($all) - 1],
        ];
    }

    /**
     * Semáforo de uso contra a capacidade: amarelo a partir de 90%, vermelho a partir de 100%. Sem capacidade, qualquer
     * uso é vermelho.
     *
     * @return 'green'|'yellow'|'red'
     */
    public static function capacityLevel(float|int $used, float|int|null $capacity): string
    {
        if (! ($capacity > 0)) {
            return $used > 0 ? 'red' : 'green';
        }
        $percent = $used / $capacity * 100;

        return $percent >= 100 ? 'red' : ($percent >= self::CAPACITY_YELLOW ? 'yellow' : 'green');
    }

    /** Data AAAA-MM-DD, como as telas exigem antes de contar dias úteis. */
    public static function validDate(mixed $value): bool
    {
        return is_string($value) && (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }
}
