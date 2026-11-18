<?php

namespace App\Metrics;

use App\Support\SprintRules;

/**
 * Painel da Sprint (espelho de public/sprint-panel.js): cards da sprint, fora o Suporte, em quatro colunas (backlog,
 * em andamento, em teste e concluídos), ordenados pela prioridade como no Painel do Bombeiro.
 */
final class SprintPanel
{
    /**
     * Colunas da sprint escolhida; sem escolha válida, a sprint mais recente.
     *
     * @return array{sprint: ?int, sprints: list<int>, counts: array{backlog: int, progress: int, test: int, done: int}, lanes: array{backlog: list<array<string, mixed>>, progress: list<array<string, mixed>>, test: list<array<string, mixed>>, done: list<array<string, mixed>>}, total: int}
     */
    public static function build(MetricsData $data, ?int $sprint = null): array
    {
        $tasks = self::filterTasks($data->rows);
        $sprints = array_values(array_unique(array_map(fn (array $row): int => (int) $row['Sprint'], $tasks)));
        rsort($sprints);
        $chosen = $sprint !== null && in_array($sprint, $sprints, true) ? $sprint : ($sprints[0] ?? null);
        $rows = $chosen === null ? [] : self::selectedRows($tasks, $chosen);
        $lanes = [];
        foreach (BombeiroPanel::LANES as $lane) {
            $lanes[$lane] = BombeiroPanel::sortByPriority(array_values(array_filter($rows, fn (array $row): bool => SprintRules::stage($row['Status'] ?? null) === $lane)));
        }

        return ['sprint' => $chosen, 'sprints' => $sprints, 'counts' => array_map('count', $lanes), 'lanes' => $lanes, 'total' => count($rows)];
    }

    /**
     * Cards com Id e sprint, fora o Suporte (que fica no Painel do Bombeiro).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function filterTasks(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $row): bool => trim((string) ($row['Id'] ?? '')) !== ''
            && is_numeric($row['Sprint'] ?? null) && (int) $row['Sprint'] == $row['Sprint'] && (int) $row['Sprint'] > 0
            && SprintRules::normalize((string) ($row['Tipo'] ?? '')) !== 'suporte'));
    }

    /**
     * Um card por Id (o último prevalece) na sprint.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function selectedRows(array $rows, int $sprint): array
    {
        $unique = [];
        foreach ($rows as $row) {
            if ((int) $row['Sprint'] === $sprint) {
                $unique[(string) $row['Id']] = $row;
            }
        }

        return array_values($unique);
    }
}
