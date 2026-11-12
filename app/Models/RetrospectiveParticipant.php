<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pessoa na sala da retrospectiva: quem consultou a sala nos últimos segundos aparece como presente.
 *
 * @property Carbon $last_seen_at
 */
#[Fillable(['retrospective_id', 'user_id', 'last_seen_at'])]
class RetrospectiveParticipant extends Model
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
