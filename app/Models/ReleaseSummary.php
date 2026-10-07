<?php

namespace App\Models;

use Database\Factories\ReleaseSummaryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Resumo de release de um card, escrito fora do dashboard (gerado no Claude Code e importado por comando).
 * source_hash identifica o conteúdo do card usado: se o card mudar, o resumo fica desatualizado.
 */
class ReleaseSummary extends Model
{
    /** @use HasFactory<ReleaseSummaryFactory> */
    use HasFactory;

    protected $guarded = [];
}
