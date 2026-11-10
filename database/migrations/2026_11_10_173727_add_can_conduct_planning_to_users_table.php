<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permissões da Planning poker: Maestro Scrum (conduz a mesa e grava a estimativa no card) e Estima na planning
     * (joga a carta com as horas; é o time de desenvolvimento). Ninguém começa com elas.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_conduct_planning')->default(false)->after('can_create_tickets');
            $table->boolean('can_estimate_planning')->default(false)->after('can_conduct_planning');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['can_conduct_planning', 'can_estimate_planning']);
        });
    }
};
