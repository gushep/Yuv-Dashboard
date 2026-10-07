<?php

namespace Database\Factories;

use App\Models\ReleaseSummary;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReleaseSummary>
 */
class ReleaseSummaryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => (string) fake()->unique()->numberBetween(10_000_000, 99_999_999),
            'summary' => 'Adicionada a seleção de canais de vídeo na tela de streaming do app.',
            'source_hash' => hash('sha256', fake()->sentence()),
            'generated_by' => 'claude-code',
        ];
    }
}
