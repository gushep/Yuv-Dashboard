<?php

namespace Database\Factories;

use App\Models\DailyAction;
use App\Models\DailyScrum;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyAction>
 */
class DailyActionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'daily_scrum_id' => DailyScrum::factory(),
            'sprint' => fn (array $attributes): int => DailyScrum::find($attributes['daily_scrum_id'])->sprint,
            'description' => fake()->sentence(),
            'responsible_id' => User::factory(),
            'status' => DailyAction::PENDING,
            'opened_by' => User::factory(),
            'opened_at' => now(),
        ];
    }

    /** Ação ligada a um card do Monday. */
    public function forItem(string $id = '901', string $name = 'Relatório de jornada'): static
    {
        return $this->state(fn (array $attributes) => ['item_id' => $id, 'item_name' => $name, 'item_url' => "https://monday.com/boards/123/pulses/{$id}"]);
    }

    public function done(string $resolution = 'Resolvido.'): static
    {
        return $this->state(fn (array $attributes) => ['status' => DailyAction::DONE, 'closed_at' => now(), 'closed_by' => User::factory(), 'resolution' => $resolution]);
    }
}
