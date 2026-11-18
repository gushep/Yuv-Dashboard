<?php

namespace App\Metrics;

use App\Models\MondayConnection;
use App\Services\MondaySnapshotService;
use App\Support\SprintRules;
use App\Support\TeamRules;

/**
 * Os mesmos dados que as telas recebem do /api/sync e do /api/config, para os cálculos do servidor (MCP) darem os
 * mesmos números das telas:
 * - rows: os cards (DATA), com as sprints encerradas congeladas no último dia, só os que têm Sprint e Nome;
 * - sprints: as sprints dos cards, em ordem (SPRINTS);
 * - sprintDates: início e fim de cada sprint pelo número (MONDAY_SPRINT_DATES);
 * - settings: capacidade padrão, pessoas fora das métricas e times (SprintRules.settings);
 * - capacityOverrides: horas de cada pessoa por sprint ("sprint|nome" => minutos);
 * - supportReserves: reserva do Bombeiro por sprint, em minutos (HoursStrip).
 */
final class MetricsData
{
    /** @var list<int> */
    public readonly array $sprints;

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $sprintDates
     * @param  array{capacityHours?: float|int, excludedPeople?: list<string>, team?: list<array{id?: string, name: string, group: string}>}  $settings
     * @param  array<string, int>  $capacityOverrides
     * @param  array<string, int>  $supportReserves
     * @param  list<array<string, mixed>>  $unassignedSupport  tickets de Suporte sem sprint (Painel do Bombeiro)
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $sprintDates = [],
        public readonly array $settings = [],
        public readonly array $capacityOverrides = [],
        public readonly array $supportReserves = [],
        public readonly ?string $today = null,
        public readonly ?string $syncedAt = null,
        public readonly array $unassignedSupport = [],
    ) {
        $sprints = array_values(array_unique(array_map(fn (array $row): int => (int) $row['Sprint'], $rows)));
        sort($sprints);
        $this->sprints = $sprints;
    }

    /**
     * Último snapshot salvo do Monday, sem esperar uma nova consulta, como as telas abrem.
     */
    public static function load(MondaySnapshotService $snapshots, ?MondayConnection $connection = null): self
    {
        $connection ??= MondayConnection::current();
        $snapshot = $snapshots->withFrozenSprints($snapshots->latest($connection, max(60, $connection->refresh_minutes * 60)), $connection);
        $rows = array_values(array_filter($snapshot['rows'] ?? [], fn (array $row): bool => is_int($row['Sprint'] ?? null) && $row['Sprint'] > 0 && is_string($row['Nome'] ?? null)));
        $dates = [];
        foreach ($snapshot['sprints'] ?? [] as $sprint) {
            $dates[(int) $sprint['number']] = $sprint;
        }

        return new self(
            rows: $rows,
            sprintDates: $dates,
            settings: $connection->metricSettings(),
            capacityOverrides: $connection->capacity_overrides ?? [],
            supportReserves: array_map('intval', $connection->forecast_settings['supportReserves'] ?? []),
            today: SprintRules::today(),
            syncedAt: $snapshot['syncedAt'] ?? null,
            unassignedSupport: array_values($snapshot['unassignedSupport'] ?? []),
        );
    }

    public function today(): string
    {
        return $this->today ?? SprintRules::today();
    }

    /**
     * Reserva do Bombeiro da sprint, em minutos: a gravada na tela Consumo de horas ou o padrão.
     */
    public function supportReserveMinutes(int $sprint): int
    {
        return (int) ($this->supportReserves[(string) $sprint] ?? MondayConnection::DEFAULT_SUPPORT_RESERVE_MINUTES);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function datesOf(int $sprint): ?array
    {
        return $this->sprintDates[$sprint] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rowsOf(int $sprint): array
    {
        return array_values(array_filter($this->rows, fn (array $row): bool => (int) $row['Sprint'] === $sprint));
    }

    public function teams(): TeamRules
    {
        return new TeamRules($this->settings);
    }
}
