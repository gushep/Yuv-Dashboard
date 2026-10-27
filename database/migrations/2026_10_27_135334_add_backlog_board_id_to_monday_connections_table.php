<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quadro do Monday exibido na aba Backlog da tela Tarefas (ex.: Product Backlog), configurado em Configurações.
     */
    public function up(): void
    {
        Schema::table('monday_connections', function (Blueprint $table) {
            $table->string('backlog_board_id')->nullable()->after('roadmap_board_id');
        });
    }

    public function down(): void
    {
        Schema::table('monday_connections', function (Blueprint $table) {
            $table->dropColumn('backlog_board_id');
        });
    }
};
