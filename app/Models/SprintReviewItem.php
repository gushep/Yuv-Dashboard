<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Decisão da review sobre um card: o aceite de uma entrega (com observações) ou, num planejado não entregue, o motivo
 * e o destino. O card continua no Monday; aqui fica só a referência pelo Id.
 */
#[Fillable(['sprint_review_id', 'item_id', 'verdict', 'notes', 'reason', 'destination', 'decided_by'])]
class SprintReviewItem extends Model
{
    public const VERDICTS = ['accepted', 'adjust', 'rejected', 'skipped'];

    public const REASONS = ['capacity', 'blocked', 'reprioritized', 'scope'];

    public const DESTINATIONS = ['next', 'backlog'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
