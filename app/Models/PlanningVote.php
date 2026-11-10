<?php

namespace App\Models;

use Database\Factories\PlanningVoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Voto em horas de uma pessoa numa rodada da planning poker.
 */
#[Fillable(['planning_round_id', 'user_id', 'hours'])]
class PlanningVote extends Model
{
    /** @use HasFactory<PlanningVoteFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['hours' => 'float'];
    }

    /**
     * @return BelongsTo<PlanningRound, $this>
     */
    public function round(): BelongsTo
    {
        return $this->belongsTo(PlanningRound::class, 'planning_round_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
