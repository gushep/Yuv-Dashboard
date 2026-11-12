<?php

namespace App\Models;

use Database\Factories\RetrospectiveActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Ação do plano de uma retrospectiva. Fica aberta (na tela da retro e na Daily) até o responsável ou o facilitador da
 * retro concluir ou descartar; reabrir mantém o histórico.
 *
 * @property Carbon|null $due_date
 * @property Carbon|null $closed_at
 */
#[Fillable(['retrospective_id', 'description', 'responsible_id', 'due_date', 'signal', 'focus', 'item_id', 'item_name', 'item_url', 'status', 'result', 'signal_reached', 'closed_at', 'closed_by'])]
class RetrospectiveAction extends Model
{
    /** @use HasFactory<RetrospectiveActionFactory> */
    use HasFactory;

    public const OPEN = 'open';

    public const DONE = 'done';

    public const DROPPED = 'dropped';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['due_date' => 'date', 'closed_at' => 'datetime', 'signal_reached' => 'boolean', 'focus' => 'integer'];
    }

    /**
     * @return BelongsTo<Retrospective, $this>
     */
    public function retrospective(): BelongsTo
    {
        return $this->belongsTo(Retrospective::class);
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
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return HasMany<RetrospectiveActionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(RetrospectiveActionEvent::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /** Aberta com o prazo vencido: atrasada a partir do dia seguinte ao prazo. */
    public function isLate(string $today): bool
    {
        return $this->isOpen() && $this->due_date !== null && $this->due_date->toDateString() < $today;
    }
}
