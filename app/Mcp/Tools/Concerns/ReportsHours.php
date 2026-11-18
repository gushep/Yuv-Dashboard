<?php

namespace App\Mcp\Tools\Concerns;

use App\Support\SprintRules;
use App\Support\TeamRules;

/**
 * Conversões das ferramentas de horas: os cálculos das telas trabalham em minutos e as ferramentas devolvem horas com
 * uma casa decimal (campos terminados em "Hours") e percentuais com uma casa (campos terminados em "Percent").
 */
trait ReportsHours
{
    /** Minutos em horas, com uma casa decimal; null continua null (sem registro). */
    protected static function hours(int|float|null $minutes): ?float
    {
        return $minutes === null ? null : round($minutes / 60, 1);
    }

    /** Percentual com uma casa decimal; null continua null (sem base de cálculo). */
    protected static function percent(int|float|null $value): ?float
    {
        return $value === null ? null : round($value, 1);
    }

    protected static function teamLabel(?string $team): string
    {
        return match ($team) {
            TeamRules::FULLSTACK => 'Full stack',
            TeamRules::PROJECTS => 'Projetos (IA)',
            default => 'Sem time',
        };
    }

    /**
     * Os quatro cards de horas (Capacidade da sprint, Full stack, Bombeiro e Projetos) em horas, com o disponível
     * (negativo = excedido) e o semáforo do previsto (Tempo Estimado) e do registrado (Tempo Real).
     *
     * @param  list<array{label: string, kind: string, capacity: int, estimate: int, registered: int, estimateLevel: string, registeredLevel: string}>  $cards
     * @return list<array<string, mixed>>
     */
    protected static function capacityCards(array $cards): array
    {
        return array_map(fn (array $card): array => [
            'label' => $card['label'],
            'kind' => $card['kind'] === '' ? 'total' : $card['kind'],
            'capacityHours' => self::hours($card['capacity']),
            'estimateHours' => self::hours($card['estimate']),
            'availableForEstimateHours' => self::hours($card['capacity'] - $card['estimate']),
            'estimateLevel' => $card['estimateLevel'],
            'registeredHours' => self::hours($card['registered']),
            'availableForRegisteredHours' => self::hours($card['capacity'] - $card['registered']),
            'registeredLevel' => $card['registeredLevel'],
        ], $cards);
    }

    /**
     * Ritmo (horas registradas por dia útil projetadas até o fim da sprint) em horas.
     *
     * @param  array<string, mixed>|null  $pace
     * @return array<string, mixed>|null
     */
    protected static function pace(?array $pace): ?array
    {
        if ($pace === null) {
            return null;
        }

        return [
            'status' => $pace['status'],
            'elapsedDays' => $pace['elapsedDays'] ?? null,
            'totalDays' => $pace['totalDays'] ?? null,
            'dailyHours' => self::hours($pace['daily'] ?? null),
            'projectedHours' => self::hours($pace['projected'] ?? null),
            'projectedPercent' => self::percent($pace['projectedPercent'] ?? null),
            'exhaustionDate' => $pace['exhaustionDate'] ?? null,
            'sprintEnd' => $pace['sprintEnd'] ?? null,
        ];
    }

    /**
     * Legenda do semáforo de capacidade.
     *
     * @return array<string, string>
     */
    protected static function levelLegend(): array
    {
        return SprintRules::LEVEL_TEXT;
    }
}
