<?php

namespace App\Models;

use Database\Factories\DailyActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Ação (encaminhamento ou pendência) registrada numa Daily. Fica pendente até alguém concluir e segue aparecendo nas
 * próximas Dailies da mesma sprint, sempre ligada à Daily em que foi aberta.
 *
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 */
#[Fillable(['daily_scrum_id', 'sprint', 'description', 'responsible_id', 'item_id', 'item_name', 'item_url', 'notice_status', 'notice_update_id', 'status', 'opened_by', 'opened_at', 'closed_at', 'closed_by', 'resolution', 'publish_status', 'monday_update_id'])]
class DailyAction extends Model
{
    /** @use HasFactory<DailyActionFactory> */
    use HasFactory;

    public const PENDING = 'pending';

    public const DONE = 'done';

    /** Publicação da conclusão no card do Monday. */
    public const PUBLISH_PENDING = 'pending';

    public const PUBLISHED = 'published';

    public const PUBLISH_FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sprint' => 'integer', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<DailyScrum, $this>
     */
    public function daily(): BelongsTo
    {
        return $this->belongsTo(DailyScrum::class, 'daily_scrum_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Histórico da ação: aberta, concluída, reaberta, publicada e falhas de publicação.
     *
     * @return HasMany<DailyActionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(DailyActionEvent::class)->orderBy('id');
    }

    public function isDone(): bool
    {
        return $this->status === self::DONE;
    }
}
