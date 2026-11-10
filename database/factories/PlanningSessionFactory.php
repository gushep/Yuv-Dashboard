<?php

namespace Database\Factories;

use App\Models\PlanningSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanningSession>
 */
class PlanningSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sprint' => 20,
            'sprint_name' => 'Sprint 20',
            'status' => PlanningSession::OPEN,
            'started_by' => User::factory(),
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => PlanningSession::CLOSED, 'closed_at' => now()]);
    }
}
