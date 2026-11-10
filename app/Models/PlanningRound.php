<?php

namespace App\Models;

use App\PlanningStatus;
use Database\Factories\PlanningRoundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rodada da planning poker de um card do Backlog do quadro Tarefas.
 *
 * @property PlanningStatus $status
 */
#[Fillable(['planning_session_id', 'item_id', 'item_name', 'item_url', 'item_owner', 'ai_estimate', 'scope_title', 'scope_html', 'status', 'started_by', 'average', 'final_estimate', 'final_source', 'decided_by', 'decision_note', 'revealed_at', 'closed_at'])]
class PlanningRound extends Model
{
    /** @use HasFactory<PlanningRoundFactory> */
    use HasFactory;

    public const SOURCE_AI = 'ai';

    public const SOURCE_PLANNING = 'planning';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PlanningStatus::class,
            'ai_estimate' => 'float',
            'average' => 'float',
            'final_estimate' => 'float',
            'revealed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PlanningVote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(PlanningVote::class);
    }

    /**
     * @return BelongsTo<PlanningSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(PlanningSession::class, 'planning_session_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
