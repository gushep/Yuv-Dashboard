<?php

namespace Database\Factories;

use App\Models\DailyScrum;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyScrum>
 */
class DailyScrumFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sprint' => 20,
            // Uma Daily por sprint e dia: cada Daily de teste fica num dia diferente do passado.
            'date' => now()->subDays(fake()->unique()->numberBetween(30, 300))->toDateString(),
            'created_by' => User::factory(),
        ];
    }
}
