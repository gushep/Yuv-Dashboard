<?php

namespace Database\Factories;

use App\Models\PlanningRound;
use App\Models\PlanningVote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanningVote>
 */
class PlanningVoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'planning_round_id' => PlanningRound::factory(),
            'user_id' => User::factory(),
            'hours' => fake()->randomElement([1, 2, 3, 5, 8, 13]),
        ];
    }
}
