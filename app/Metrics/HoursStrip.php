<?php

namespace App\Metrics;

use App\Support\SprintRules;

/**
 * Tabela de horas da barra superior (public/hours-strip.js): para cada parte da capacidade (Capacidade, Full stack,
 * Bombeiro e Projetos), o uso e o disponível nas linhas Planejado (Tempo Estimado) e Realizado (Tempo Real).
 */
final class HoursStrip
{
    /** Rótulos curtos das colunas, como na barra superior. */
    public const SHORT_LABELS = [
        'Capacidade da sprint' => 'Capacidade',
        'Full stack para a sprint' => 'Full stack',
        'Bombeiro (sustentação)' => 'Bombeiro',
        'Projetos (IA)' => 'Projetos',
    ];

    /**
     * Reserva do Bombeiro da sprint, em minutos: a salva no Consumo de horas ou o padrão.
     */
    public static function reserveFor(MetricsData $data, int $sprint): int
    {
        return $data->supportReserveMinutes($sprint);
    }

    /**
     * Linhas Planejado e Realizado de cada parte: uso, capacidade, percentual (arredondado, null sem capacidade),
     * disponível (negativo = excedido) e semáforo.
     *
     * @return array{sprint: int, columns: list<array{label: string, shortLabel: string, kind: string, capacity: int, planned: array{used: int, percent: ?int, available: int, level: string}, realized: array{used: int, percent: ?int, available: int, level: string}}>}
     */
    public static function table(MetricsData $data, int $sprint): array
    {
        $cards = Capacity::for($data)->sprintSummary($data, $sprint)['cards'];
        $cell = fn (int $used, int $capacity): array => [
            'used' => $used,
            'percent' => $capacity > 0 ? SprintRules::round($used / $capacity * 100) : null,
            'available' => $capacity - $used,
            'level' => SprintRules::capacityLevel($used, $capacity),
        ];

        return ['sprint' => $sprint, 'columns' => array_map(fn (array $card): array => [
            'label' => $card['label'],
            'shortLabel' => self::SHORT_LABELS[$card['label']] ?? $card['label'],
            'kind' => $card['kind'],
            'capacity' => $card['capacity'],
            'planned' => $cell($card['estimate'], $card['capacity']),
            'realized' => $cell($card['registered'], $card['capacity']),
        ], $cards)];
    }
}
