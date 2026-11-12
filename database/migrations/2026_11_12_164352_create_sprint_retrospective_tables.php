<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint Retrospective: uma retro por sprint, conduzida ao vivo pelo facilitador (etapas, cronômetro, cartas Manter /
     * Melhorar / Experimentar com votos, temas, focos e plano de ação). As ações seguem em acompanhamento depois da retro
     * (e na Daily) até serem concluídas ou descartadas; o histórico de cada ação nunca é apagado.
     */
    public function up(): void
    {
        Schema::create('retrospectives', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sprint')->unique();
            $table->string('sprint_name');
            $table->string('status', 10)->default('open');
            $table->foreignId('facilitator_id')->nullable()->constrained('users')->nullOnDelete();
            // Etapa atual (0 a 6), quando ela começou e o tempo já gasto em cada etapa (segundos), para o cronômetro.
            $table->unsignedTinyInteger('step')->default(0);
            $table->timestamp('step_started_at')->nullable();
            $table->json('step_seconds')->nullable();
            $table->text('goal');
            $table->json('rules');
            $table->text('context')->nullable();
            $table->text('dependencies')->nullable();
            // Votos do time sobre o objetivo da sprint: {user_id: yes|partial|no}.
            $table->json('goal_votes')->nullable();
            $table->timestamp('revealed_at')->nullable();
            // Indicadores da sprint congelados no encerramento.
            $table->json('metrics')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('retrospective_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retrospective_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['retrospective_id', 'user_id']);
        });

        Schema::create('retrospective_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retrospective_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('column', 10);
            $table->text('text');
            // Carta agrupada pelo facilitador dentro de outra parecida.
            $table->foreignId('merged_into_id')->nullable()->constrained('retrospective_cards')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('retrospective_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retrospective_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['retrospective_card_id', 'user_id']);
        });

        Schema::create('retrospective_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retrospective_id')->constrained()->cascadeOnDelete();
            $table->foreignId('retrospective_card_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 500);
            $table->text('question')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedTinyInteger('focus')->nullable();
            $table->timestamps();
        });

        Schema::create('retrospective_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retrospective_id')->constrained()->restrictOnDelete();
            $table->text('description');
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->text('signal')->nullable();
            $table->unsignedTinyInteger('focus')->nullable();
            $table->string('item_id', 20)->nullable();
            $table->string('item_name')->nullable();
            $table->string('item_url', 500)->nullable();
            $table->string('status', 10)->default('open');
            $table->text('result')->nullable();
            $table->boolean('signal_reached')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('retrospective_action_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retrospective_action_id')->constrained()->cascadeOnDelete();
            // Retro em que a ação foi revisada (etapa "Ações anteriores"), quando for o caso.
            $table->foreignId('retrospective_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retrospective_action_events');
        Schema::dropIfExists('retrospective_actions');
        Schema::dropIfExists('retrospective_topics');
        Schema::dropIfExists('retrospective_votes');
        Schema::dropIfExists('retrospective_cards');
        Schema::dropIfExists('retrospective_participants');
        Schema::dropIfExists('retrospectives');
    }
};
