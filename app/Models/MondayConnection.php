<?php

namespace App\Models;

use App\Support\TeamRules;
use Illuminate\Database\Eloquent\Model;

class MondayConnection extends Model
{
    /** Reserva de suporte padrão por sprint: 80h, descontadas da capacidade full stack. */
    public const DEFAULT_SUPPORT_RESERVE_MINUTES = 4800;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'column_map' => 'array',
            'capacity_overrides' => 'array',
            'forecast_settings' => 'array',
            'metric_settings' => 'array',
            'refresh_minutes' => 'integer',
        ];
    }

    /**
     * Regras configuráveis dos indicadores, com os padrões aplicados. `excludedPeople` só vale enquanto ninguém foi
     * classificado em `team` (ver TeamRules).
     *
     * @return array{capacityHours: float|int, excludedPeople: list<string>, team: list<array{id: string, name: string, group: string}>}
     */
    public function metricSettings(): array
    {
        $settings = $this->metric_settings ?? [];

        return [
            'capacityHours' => $settings['capacityHours'] ?? 80,
            'excludedPeople' => array_values($settings['excludedPeople'] ?? []),
            'team' => array_values($settings['team'] ?? []),
        ];
    }

    public function teamRules(): TeamRules
    {
        return new TeamRules($this->metricSettings());
    }

    /** Reserva de suporte (Bombeiro) da sprint, em minutos; descontada da capacidade full stack. */
    public function supportReserveMinutes(int $sprint): int
    {
        return (int) ($this->forecast_settings['supportReserves'][(string) $sprint] ?? self::DEFAULT_SUPPORT_RESERVE_MINUTES);
    }

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'board_id' => '',
            'column_map' => [],
            'capacity_overrides' => [],
            'refresh_minutes' => 5,
        ]);
    }
}
