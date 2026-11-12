<?php

namespace App\Models;

use Database\Factories\RetrospectiveFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Retrospectiva de uma sprint, conduzida ao vivo pelo facilitador em sete etapas. Ao encerrar, os indicadores da sprint
 * ficam congelados e as ações seguem em acompanhamento.
 *
 * @property Carbon $started_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $step_started_at
 * @property Carbon|null $revealed_at
 * @property array<int, array{title: string, text: string}> $rules
 * @property array<string, int>|null $step_seconds
 * @property array<string, string>|null $goal_votes
 * @property array<string, mixed>|null $metrics
 */
#[Fillable(['sprint', 'sprint_name', 'status', 'facilitator_id', 'step', 'step_started_at', 'step_seconds', 'goal', 'rules', 'context', 'dependencies', 'goal_votes', 'revealed_at', 'metrics', 'started_at', 'closed_at'])]
class Retrospective extends Model
{
    /** @use HasFactory<RetrospectiveFactory> */
    use HasFactory;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sprint' => 'integer', 'step' => 'integer', 'step_started_at' => 'datetime', 'step_seconds' => 'array', 'rules' => 'array',
            'goal_votes' => 'array', 'revealed_at' => 'datetime', 'metrics' => 'array', 'started_at' => 'datetime', 'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function facilitator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'facilitator_id');
    }

    /**
     * @return HasMany<RetrospectiveParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(RetrospectiveParticipant::class);
    }

    /**
     * @return HasMany<RetrospectiveCard, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(RetrospectiveCard::class)->orderBy('id');
    }

    /**
     * @return HasMany<RetrospectiveTopic, $this>
     */
    public function topics(): HasMany
    {
        return $this->hasMany(RetrospectiveTopic::class)->orderBy('id');
    }

    /**
     * @return HasMany<RetrospectiveAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(RetrospectiveAction::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }
}
