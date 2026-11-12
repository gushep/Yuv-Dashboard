<?php

namespace Database\Factories;

use App\Models\Retrospective;
use App\Models\RetrospectiveAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RetrospectiveAction>
 */
class RetrospectiveActionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'retrospective_id' => Retrospective::factory()->closed(),
            'description' => fake()->sentence(),
            'responsible_id' => User::factory(),
            'due_date' => now()->addWeek()->toDateString(),
            'signal' => fake()->sentence(),
            'status' => RetrospectiveAction::OPEN,
        ];
    }

    public function done(string $result = 'Feito.'): static
    {
        return $this->state(fn (array $attributes) => ['status' => RetrospectiveAction::DONE, 'result' => $result, 'signal_reached' => true, 'closed_at' => now(), 'closed_by' => User::factory()]);
    }
}
