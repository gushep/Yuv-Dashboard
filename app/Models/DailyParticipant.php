<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pessoa que esteve na Daily do dia: o primeiro e o último momento em que estava com a tela aberta.
 *
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 */
#[Fillable(['daily_scrum_id', 'user_id', 'first_seen_at', 'last_seen_at'])]
class DailyParticipant extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
