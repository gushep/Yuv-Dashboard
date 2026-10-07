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
}
