<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tema da discussão: vem de uma carta votada ou é incluído pelo facilitador. Até dois temas viram os focos da próxima
 * sprint (`focus` 1 ou 2).
 */
#[Fillable(['retrospective_id', 'retrospective_card_id', 'title', 'question', 'notes', 'focus'])]
class RetrospectiveTopic extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['focus' => 'integer'];
    }

    /**
     * @return BelongsTo<RetrospectiveCard, $this>
     */
    public function card(): BelongsTo
    {
        return $this->belongsTo(RetrospectiveCard::class, 'retrospective_card_id');
    }
}
