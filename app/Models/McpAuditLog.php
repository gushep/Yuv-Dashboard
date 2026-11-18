<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma chamada ao MCP: quem pediu, por qual ferramenta de IA, qual método/ferramenta do MCP e o status HTTP.
 * Os registros com mais de RETENTION_DAYS dias são apagados pelo agendador (model:prune).
 */
#[Fillable(['user_id', 'client_id', 'client_name', 'method', 'tool', 'status'])]
class McpAuditLog extends Model
{
    use Prunable;

    public const RETENTION_DAYS = 90;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
