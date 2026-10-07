<?php

namespace App\Services;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Previsão probabilística (Monte Carlo): sorteia, com reposição, a entrega diária real do time
 * para cada dia útil restante e mede quantos cards seriam concluídos em cada simulação.
 */
class ThroughputForecast
{
    /**
     * @param  list<int>  $dailySamples  cards concluídos por dia útil no histórico
     * @return array{available: bool, runs: int, samples: int, remainingDays: int, mean: ?float, p50: ?int, p85: ?int, probabilityAll: ?float, probabilityCommitted: ?float}
     */
    public function simulate(array $dailySamples, int $remainingDays, int $remainingItems, int $remainingCommitted, int $runs = 10000, int $seed = 1): array
    {
        $result = ['available' => false, 'runs' => 0, 'samples' => count($dailySamples), 'remainingDays' => $remainingDays,
            'mean' => null, 'p50' => null, 'p85' => null, 'probabilityAll' => null, 'probabilityCommitted' => null];
        if (count($dailySamples) < 10 || array_sum($dailySamples) === 0) {
            return $result;
        }
        $randomizer = new Randomizer(new Mt19937($seed));
        $last = count($dailySamples) - 1;
        $outcomes = [];
        for ($run = 0; $run < $runs; $run++) {
            $total = 0;
            for ($day = 0; $day < $remainingDays; $day++) {
                $total += $dailySamples[$randomizer->getInt(0, $last)];
            }
            $outcomes[] = $total;
        }
        sort($outcomes);
        $atLeast = fn (int $target): float => round(count(array_filter($outcomes, fn (int $value): bool => $value >= $target)) / $runs * 100, 1);

        return [
            'available' => true,
            'runs' => $runs,
            'samples' => count($dailySamples),
            'remainingDays' => $remainingDays,
            'mean' => round(array_sum($dailySamples) / count($dailySamples), 2),
            'p50' => $outcomes[(int) floor($runs * 0.5)],
            // 85% das simulações concluem pelo menos esta quantidade.
            'p85' => $outcomes[(int) floor($runs * 0.15)],
            'probabilityAll' => $atLeast($remainingItems),
            'probabilityCommitted' => $atLeast($remainingCommitted),
        ];
    }
}
