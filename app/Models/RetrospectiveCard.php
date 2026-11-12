<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Carta da visão do time (Manter, Melhorar ou Experimentar). Fica virada para os outros até o facilitador revelar.
 */
#[Fillable(['retrospective_id', 'user_id', 'column', 'text', 'merged_into_id'])]
class RetrospectiveCard extends Model
{
    public const COLUMNS = ['keep', 'improve', 'try'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<RetrospectiveVote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(RetrospectiveVote::class);
    }
}
