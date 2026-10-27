<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Quadro de Tarefas da YUV: só essa instalação recebe os quadros padrão das abas. */
    private const YUV_TASKS_BOARD = '18413632359';

    /** @var array<string, string> Coluna de configuração => quadro (Funil de Demandas e Product Backlog). */
    private const DEFAULTS = ['roadmap_board_id' => '18401224089', 'backlog_board_id' => '18432927592'];

    /**
     * Preenche os quadros das abas Roadmap e Backlog que ainda estiverem vazios, para não precisar configurar à mão.
     */
    public function up(): void
    {
        foreach (self::DEFAULTS as $column => $boardId) {
            DB::table('monday_connections')->where('board_id', self::YUV_TASKS_BOARD)->whereNull($column)->update([$column => $boardId]);
        }
    }

    public function down(): void
    {
        foreach (self::DEFAULTS as $column => $boardId) {
            DB::table('monday_connections')->where('board_id', self::YUV_TASKS_BOARD)->where($column, $boardId)->update([$column => null]);
        }
    }
};
