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
            'refresh_minutes' => 'integer',
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
