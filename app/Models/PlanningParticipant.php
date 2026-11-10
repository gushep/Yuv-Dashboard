<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quem está sentado na mesa: a tela aberta renova o last_seen_at a cada consulta.
 */
#[Fillable(['planning_session_id', 'user_id', 'last_seen_at'])]
class PlanningParticipant extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
