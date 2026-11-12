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
 * ação); o tempo da Daily vai da primeira entrada até a última saída de quem esteve na tela.
 *
 * @property Carbon $date
 */
#[Fillable(['sprint', 'date', 'created_by'])]
class DailyScrum extends Model
{
    /** @use HasFactory<DailyScrumFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sprint' => 'integer', 'date' => 'date'];
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
