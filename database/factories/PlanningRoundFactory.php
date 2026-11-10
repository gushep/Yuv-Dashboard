<?php

namespace Database\Factories;

use App\Models\PlanningRound;
use App\Models\PlanningSession;
use App\Models\User;
use App\PlanningStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanningRound>
 */
class PlanningRoundFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $itemId = (string) fake()->unique()->numberBetween(13000000000, 13999999999);

        return [
            'planning_session_id' => PlanningSession::factory(),
            'item_id' => $itemId,
            'item_name' => fake()->sentence(4),
            'item_url' => "https://example.monday.com/boards/1/pulses/{$itemId}",
            'ai_estimate' => 8,
            'status' => PlanningStatus::Reading,
            'started_by' => User::factory(),
        ];
    }

    /**
     * Estimativa aberta: cada pessoa joga a carta virada.
     */
    public function voting(): static
    {
        return $this->state(fn (array $attributes) => ['status' => PlanningStatus::Voting]);
    }

    /**
     * Votos abertos, com a média já calculada.
     */
    public function revealed(float $average): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PlanningStatus::Revealed,
            'average' => $average,
            'revealed_at' => now(),
        ]);
    }

    /**
     * Card sem estimativa da IA no Monday.
     */
    public function withoutAiEstimate(): static
    {
        return $this->state(fn (array $attributes) => ['ai_estimate' => null]);
    }
}
