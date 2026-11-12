<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Feedback dado na review. Vira demanda no Funil de Demandas do Monday só quando alguém pede, uma única vez.
 */
#[Fillable(['sprint_review_id', 'author_id', 'given_by', 'type', 'text', 'item_id', 'item_name', 'demand_status', 'demand_item_id', 'demand_url', 'demand_error'])]
class SprintReviewFeedback extends Model
{
    public const TYPES = ['improvement' => 'Melhoria', 'bug' => 'Bug', 'idea' => 'Nova ideia', 'praise' => 'Elogio'];

    protected $table = 'sprint_review_feedbacks';

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function giver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'given_by');
    }
}
