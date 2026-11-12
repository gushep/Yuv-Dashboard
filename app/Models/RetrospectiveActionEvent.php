<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Passo do histórico de uma ação da retro: criada, concluída, descartada, reaberta ou mantida (revisão na retro seguinte).
 *
 * @property Carbon|null $due_date
 */
#[Fillable(['retrospective_action_id', 'retrospective_id', 'type', 'user_id', 'note', 'due_date'])]
class RetrospectiveActionEvent extends Model
{
    public const CREATED = 'created';

    public const DONE = 'done';

    public const DROPPED = 'dropped';

    public const REOPENED = 'reopened';

    public const CONTINUED = 'continued';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['due_date' => 'date'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
