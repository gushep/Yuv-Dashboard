<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estimativa em horas de cada pessoa numa rodada (a carta virada na mesa); jogar de novo troca a carta. Sem horas é o
     * voto vazio de quem não tem contexto da demanda: aparece na revelação, mas fica fora da média.
     */
    public function up(): void
    {
        Schema::create('planning_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planning_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('hours', 6, 2)->nullable();
            $table->timestamps();
            $table->unique(['planning_round_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_votes');
    }
};
