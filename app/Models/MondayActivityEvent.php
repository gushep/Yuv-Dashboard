<?php

namespace App\Models;

use Database\Factories\MondayActivityEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Evento do log de atividades do Monday, copiado para preservar o histórico além da retenção do Monday.
 * Eventos em lote geram uma linha por card (item_id); eventos do quadro usam item_id vazio.
 */
class MondayActivityEvent extends Model
{
    /** @use HasFactory<MondayActivityEventFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'previous_value' => 'array',
            'value' => 'array',
            'data' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
