<?php

namespace App\Metrics;

use App\Support\SprintRules;
use Collator;

/**
 * Painel do Bombeiro (espelho de public/bombeiro.js): tickets de Suporte da sprint em quatro colunas (backlog, em
 * andamento, em teste e concluídos), ordenados pela prioridade. Inclui os tickets de Suporte sem sprint.
 */
final class BombeiroPanel
{
    public const LANES = ['backlog', 'progress', 'test', 'done'];

    /**
     * Colunas do painel na sprint escolhida ('all' para todas, 'unassigned' para os sem sprint). Sem escolha válida,
     * vale a sprint mais recente; sem sprint nenhuma, os sem sprint ou todas, como a tela.
     *
     * @return array{sprint: int|string, sprints: list<int>, hasUnassigned: bool, counts: array{backlog: int, progress: int, test: int, done: int}, lanes: array{backlog: list<array<string, mixed>>, progress: list<array<string, mixed>>, test: list<array<string, mixed>>, done: list<array<string, mixed>>}, total: int}
     */
    public static function build(MetricsData $data, int|string|null $sprint = null): array
    {
        $allRows = [...$data->rows, ...$data->unassignedSupport];
        $support = self::filterSupport($allRows);
        $sprints = [];
        foreach ($allRows as $row) {
            $number = $row['Sprint'] ?? null;
            if ($number !== null && is_numeric($number) && (int) $number == $number && (int) $number > 0) {
                $sprints[(int) $number] = true;
            }
        }
        $sprints = array_keys($sprints);
        rsort($sprints);
        $hasUnassigned = (bool) array_filter($support, fn (array $row): bool => ($row['Sprint'] ?? null) === null);
        if (is_numeric($sprint)) {
            $sprint = (int) $sprint;
        }
        $chosen = $sprint === 'all' || ($sprint === 'unassigned' && $hasUnassigned) || (is_int($sprint) && in_array($sprint, $sprints, true))
            ? $sprint
            : ($sprints[0] ?? ($hasUnassigned ? 'unassigned' : 'all'));
        $rows = self::selectedRows($support, $chosen);
        $lanes = [];
        foreach (self::LANES as $lane) {
            $lanes[$lane] = self::sortByPriority(array_values(array_filter($rows, fn (array $row): bool => self::statusBucket($row['Status'] ?? null) === $lane)));
        }

        return [
            'sprint' => $chosen,
            'sprints' => $sprints,
            'hasUnassigned' => $hasUnassigned,
            'counts' => array_map('count', $lanes),
            'lanes' => $lanes,
            'total' => count($rows),
        ];
    }

    /**
     * Só os tickets de Suporte com Id.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function filterSupport(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $row): bool => trim((string) ($row['Id'] ?? '')) !== '' && self::normalized($row['Tipo'] ?? null) === 'suporte'));
    }

    /** Coluna do ticket: backlog só com o status Backlog; cancelados entram em concluídos. */
    public static function statusBucket(?string $status): string
    {
        if (self::normalized($status) === 'backlog') {
            return 'backlog';
        }
        if (SprintRules::isDone($status) || SprintRules::isRemoved($status)) {
            return 'done';
        }

        return SprintRules::isInTest($status) ? 'test' : 'progress';
    }

    /**
     * Prioridade com rótulo, classe e ordem (Crítica primeiro; sem prioridade por último).
     *
     * @return array{label: string, css: string, rank: int}
     */
    public static function priorityMeta(mixed $priority): array
    {
        $label = trim((string) ($priority ?? '')) ?: 'Não informada';
        $key = SprintRules::normalize($label);
        [$css, $rank] = ['critica' => ['critical', 0], 'critico' => ['critical', 0], 'alta' => ['high', 1], 'media' => ['medium', 2], 'baixa' => ['low', 3]][$key] ?? ['neutral', 4];

        return ['label' => $label, 'css' => $css, 'rank' => $rank];
    }

    /**
     * Um card por Id (o último prevalece), na sprint escolhida.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function selectedRows(array $rows, int|string $sprint): array
    {
        $filtered = match (true) {
            $sprint === 'all' => $rows,
            $sprint === 'unassigned' => array_filter($rows, fn (array $row): bool => ($row['Sprint'] ?? null) === null),
            default => array_filter($rows, fn (array $row): bool => is_numeric($row['Sprint'] ?? null) && (float) $row['Sprint'] === (float) $sprint),
        };
        $unique = [];
        foreach ($filtered as $row) {
            $unique[(string) $row['Id']] = $row;
        }

        return array_values($unique);
    }

    /**
     * Ordem da coluna: prioridade e, no empate, o nome.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function sortByPriority(array $rows): array
    {
        $collator = class_exists(Collator::class) ? new Collator('pt_BR') : null;
        usort($rows, fn (array $a, array $b): int => (self::priorityMeta($a['Prioridade'] ?? null)['rank'] <=> self::priorityMeta($b['Prioridade'] ?? null)['rank'])
            ?: ($collator ? (int) $collator->compare((string) ($a['Nome'] ?? ''), (string) ($b['Nome'] ?? '')) : strcasecmp((string) ($a['Nome'] ?? ''), (string) ($b['Nome'] ?? ''))));

        return $rows;
    }

    private static function normalized(mixed $value): string
    {
        return mb_strtolower(trim((string) ($value ?? '')));
    }
}
