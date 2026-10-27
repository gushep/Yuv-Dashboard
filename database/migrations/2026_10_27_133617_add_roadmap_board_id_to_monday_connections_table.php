<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quadro do Monday exibido na aba Roadmap da tela Tarefas (ex.: Funil de Demandas), configurado em Configurações.
     */
    public function up(): void
    {
        Schema::table('monday_connections', function (Blueprint $table) {
            $table->string('roadmap_board_id')->nullable()->after('board_id');
        });
    }

    public function down(): void
    {
        Schema::table('monday_connections', function (Blueprint $table) {
            $table->dropColumn('roadmap_board_id');
        });
    }
};
