<?php

namespace Database\Factories;

use App\Models\SprintCommitment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SprintCommitment>
 */
class SprintCommitmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sprint' => fake()->numberBetween(1, 30),
            'item_id' => (string) fake()->unique()->numberBetween(10_000_000, 99_999_999),
            'committed' => true,
        ];
    }

    public function desirable(): static
    {
        return $this->state(['committed' => false]);
    }
}
