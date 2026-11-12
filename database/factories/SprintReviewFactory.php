<?php

namespace Database\Factories;

use App\Models\SprintReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SprintReview>
 */
class SprintReviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sprint = fake()->unique()->numberBetween(1, 15);

        return [
            'sprint' => $sprint,
            'sprint_name' => "Sprint {$sprint}",
            'status' => SprintReview::OPEN,
            'facilitator_id' => User::factory(),
            'step' => 0,
            'step_started_at' => now(),
            'started_at' => now(),
        ];
    }

    /** Review encerrada, com o resultado congelado. */
    public function closed(array $items = []): static
    {
        return $this->state(fn (array $attributes) => ['status' => SprintReview::CLOSED, 'step' => 5, 'step_started_at' => null, 'closed_at' => now(), 'metrics' => ['delivered' => count($items)], 'items' => $items]);
    }
}
