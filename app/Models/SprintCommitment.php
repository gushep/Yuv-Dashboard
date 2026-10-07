<?php

namespace App\Models;

use Database\Factories\SprintCommitmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Marca explícita de compromisso de um card na sprint. Sem marca, o card planejado conta como compromisso.
 */
class SprintCommitment extends Model
{
    /** @use HasFactory<SprintCommitmentFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sprint' => 'integer',
            'committed' => 'boolean',
        ];
    }

    /**
     * Marcas explícitas da sprint, por card.
     *
     * @return array<string, bool>
     */
    public static function forSprint(int $sprint): array
    {
        return static::query()->where('sprint', $sprint)->pluck('committed', 'item_id')->map(fn (mixed $value): bool => (bool) $value)->all();
    }
}
