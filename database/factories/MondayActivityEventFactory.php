<?php

namespace Database\Factories;

use App\Models\MondayActivityEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MondayActivityEvent>
 */
class MondayActivityEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $itemId = (string) fake()->numberBetween(10_000_000, 99_999_999);
        $previous = ['label' => ['text' => 'Em progresso']];
        $value = ['label' => ['text' => 'Implementado']];

        return [
            'monday_id' => fake()->uuid(),
            'board_id' => '123',
            'item_id' => $itemId,
            'event' => 'update_column_value',
            'column_id' => 'status',
            'column_title' => 'Status',
            'previous_value' => $previous,
            'value' => $value,
            'user_id' => (string) fake()->numberBetween(1_000, 9_999),
            'data' => ['pulse_id' => (int) $itemId, 'column_id' => 'status', 'previous_value' => $previous, 'value' => $value],
            'occurred_at' => fake()->dateTimeBetween('-30 days'),
        ];
    }
}
