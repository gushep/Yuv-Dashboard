<?php

namespace App\Models;

use Database\Factories\DailyScrumFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Daily Scrum de uma sprint num dia. É criada quando a primeira pessoa abre a Daily do dia (ou registra a primeira
 * ação). O tempo conta do botão Iniciar Daily até o Encerrar Daily; retomada, o tempo dos trechos vai somando.
 *
 * @property Carbon $date
 * @property Carbon|null $started_at
 * @property Carbon|null $opened_at
 * @property int $open_seconds
 * @property Carbon|null $closed_at
 */
#[Fillable(['sprint', 'date', 'created_by', 'started_at', 'started_by', 'opened_at', 'open_seconds', 'closed_at', 'closed_by'])]
class DailyScrum extends Model
{
    /** @use HasFactory<DailyScrumFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sprint' => 'integer', 'date' => 'date', 'started_at' => 'datetime', 'opened_at' => 'datetime', 'open_seconds' => 'integer', 'closed_at' => 'datetime'];
    }

    /** Já foi iniciada pelo botão (Dailies anteriores aos botões contam o tempo pela presença na tela). */
    public function isStarted(): bool
    {
        return $this->started_at !== null;
    }

    /** O tempo está correndo: iniciada (ou retomada) e ainda não encerrada. */
    public function isRunning(): bool
    {
        return $this->opened_at !== null;
    }

    /**
     * Ações abertas nesta Daily (a origem de cada uma).
     *
     * @return HasMany<DailyAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(DailyAction::class);
    }

    /**
     * @return HasMany<DailyParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(DailyParticipant::class);
    }

    /**
     * Quem clicou em Iniciar Daily.
     *
     * @return BelongsTo<User, $this>
     */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * Quem clicou em Encerrar Daily por último.
     *
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
