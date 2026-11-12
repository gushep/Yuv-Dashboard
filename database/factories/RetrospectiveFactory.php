<?php

namespace Database\Factories;

use App\Models\Retrospective;
use App\Models\User;
use App\Services\RetrospectiveService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Retrospective>
 */
class RetrospectiveFactory extends Factory
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
            'status' => Retrospective::OPEN,
            'facilitator_id' => User::factory(),
            'step' => 0,
            'step_started_at' => now(),
            'goal' => RetrospectiveService::DEFAULT_GOAL,
            'rules' => RetrospectiveService::DEFAULT_RULES,
            'started_at' => now(),
        ];
    }

    /** Retro encerrada, com os indicadores congelados. */
    public function closed(array $metrics = ['delivered' => 10]): static
    {
        return $this->state(fn (array $attributes) => ['status' => Retrospective::CLOSED, 'step' => 6, 'closed_at' => now(), 'metrics' => $metrics]);
    }
}
