<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sessão de planning poker de uma sprint (a mesa), aberta pelo Maestro Scrum, e quem está sentado nela.
     */
    public function up(): void
    {
        Schema::create('planning_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sprint');
            $table->string('sprint_name');
            $table->string('status', 10)->default('open');
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
        });

        Schema::create('planning_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planning_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['planning_session_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_participants');
        Schema::dropIfExists('planning_sessions');
    }
};
