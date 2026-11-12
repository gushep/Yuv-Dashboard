<?php

namespace App\Models;

use Database\Factories\PlanningSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mesa da planning poker de uma sprint: aberta pelo Maestro Scrum, uma por vez para o time todo.
 */
#[Fillable(['sprint', 'sprint_name', 'goal', 'status', 'started_by', 'closed_at'])]
class PlanningSession extends Model
{
    /** @use HasFactory<PlanningSessionFactory> */
    use HasFactory;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sprint' => 'integer', 'closed_at' => 'datetime'];
    }

    /**
     * @return HasMany<PlanningRound, $this>
     */
    public function rounds(): HasMany
    {
        return $this->hasMany(PlanningRound::class);
    }

    /**
     * @return HasMany<PlanningParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(PlanningParticipant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }
}
