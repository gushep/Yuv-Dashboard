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
#[Fillable(['sprint', 'sprint_name', 'goal', 'status', 'opened_at', 'open_seconds', 'started_by', 'closed_at'])]
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
        return ['sprint' => 'integer', 'closed_at' => 'datetime', 'opened_at' => 'datetime', 'open_seconds' => 'integer'];
    }

    /**
     * Tempo da mesa aberta, somando todas as aberturas (a atual conta até agora). Mesas anteriores ao registro do tempo
     * usam a abertura e o encerramento.
     */
    public function durationSeconds(): int
    {
        if ($this->open_seconds === 0 && $this->opened_at === null) {
            return $this->closed_at ? (int) $this->created_at->diffInSeconds($this->closed_at, true) : (int) $this->created_at->diffInSeconds(now(), true);
        }

        return $this->open_seconds + ($this->opened_at ? (int) $this->opened_at->diffInSeconds(now(), true) : 0);
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
