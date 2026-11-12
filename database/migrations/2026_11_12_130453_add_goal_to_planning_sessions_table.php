<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Objetivo da Sprint, registrado ao encerrar a planning. Fica na mesa da sprint (uma por sprint, reaberta quando
     * preciso) e é reutilizado pelo histórico e pelo Daily Hub. Sprints antigas ficam sem objetivo (nulo).
     */
    public function up(): void
    {
        Schema::table('planning_sessions', function (Blueprint $table) {
            $table->text('goal')->nullable()->after('sprint_name');
        });
    }

    public function down(): void
    {
        Schema::table('planning_sessions', function (Blueprint $table) {
            $table->dropColumn('goal');
        });
    }
};
