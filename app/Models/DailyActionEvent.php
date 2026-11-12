<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um passo do histórico de uma ação da Daily. Conclusões e reaberturas nunca são apagadas: a conclusão anterior fica
 * aqui mesmo depois de a ação ser reaberta e concluída de novo.
 */
#[Fillable(['daily_action_id', 'type', 'user_id', 'resolution', 'monday_update_id', 'error'])]
class DailyActionEvent extends Model
{
    public const OPENED = 'opened';

    public const DONE = 'done';

    public const REOPENED = 'reopened';

    public const PUBLISHED = 'published';

    public const PUBLISH_FAILED = 'publish_failed';

    /** Aviso de abertura (responsabilidade pendente) comentado no card. */
    public const NOTICED = 'noticed';

    public const NOTICE_FAILED = 'notice_failed';

    /**
     * @return BelongsTo<DailyAction, $this>
     */
    public function action(): BelongsTo
    {
        return $this->belongsTo(DailyAction::class, 'daily_action_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
