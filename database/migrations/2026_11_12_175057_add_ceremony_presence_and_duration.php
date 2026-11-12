<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Presentes e tempo das cerimônias. A mesa da planning pode ser reaberta: o tempo de cada abertura vai somando em
     * `open_seconds` (a abertura atual começa em `opened_at`). Na Daily, quem abriu a Daily do dia fica registrado com o
     * primeiro e o último momento em que esteve na tela.
     */
    public function up(): void
    {
        Schema::table('planning_sessions', function (Blueprint $table) {
            $table->timestamp('opened_at')->nullable()->after('status');
            $table->unsignedInteger('open_seconds')->default(0)->after('opened_at');
        });

        Schema::create('daily_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_scrum_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['daily_scrum_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_participants');
        Schema::table('planning_sessions', function (Blueprint $table) {
            $table->dropColumn(['opened_at', 'open_seconds']);
        });
    }
};
