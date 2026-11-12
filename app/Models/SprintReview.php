<?php

namespace App\Models;

use Database\Factories\SprintReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Sprint Review de uma sprint, conduzida ao vivo pelo Maestro Scrum. Ao encerrar, o resultado e a lista de entregas ficam
 * congelados.
 *
 * @property Carbon $started_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $step_started_at
 * @property array<string, int>|null $step_seconds
 * @property array<string, mixed>|null $metrics
 * @property list<array<string, mixed>>|null $items
 */
#[Fillable(['sprint', 'sprint_name', 'status', 'facilitator_id', 'step', 'step_started_at', 'step_seconds', 'current_item_id', 'goal_result', 'goal_note', 'metrics', 'items', 'started_at', 'closed_at'])]
class SprintReview extends Model
{
    /** @use HasFactory<SprintReviewFactory> */
    use HasFactory;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sprint' => 'integer', 'step' => 'integer', 'step_started_at' => 'datetime', 'step_seconds' => 'array',
            'metrics' => 'array', 'items' => 'array', 'started_at' => 'datetime', 'closed_at' => 'datetime',
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
     * @return HasMany<SprintReviewParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(SprintReviewParticipant::class);
    }

    /**
     * @return HasMany<SprintReviewItem, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(SprintReviewItem::class);
    }

    /**
     * @return HasMany<SprintReviewFeedback, $this>
     */
    public function feedbacks(): HasMany
    {
        return $this->hasMany(SprintReviewFeedback::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }
}
