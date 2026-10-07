<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MondayConnection extends Model
{
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
     * Regras configuráveis dos indicadores, com os padrões aplicados.
     *
     * @return array{capacityHours: float|int, excludedPeople: list<string>}
     */
    public function metricSettings(): array
    {
        $settings = $this->metric_settings ?? [];

        return [
            'capacityHours' => $settings['capacityHours'] ?? 80,
            'excludedPeople' => array_values($settings['excludedPeople'] ?? []),
        ];
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
