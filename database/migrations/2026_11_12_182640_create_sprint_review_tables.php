<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint Review: uma review por sprint, conduzida ao vivo pelo Maestro Scrum em seis etapas. As entregas vêm do
     * Monday; aqui ficam só o aceite de cada entrega (e o destino dos planejados não entregues), os feedbacks (que podem
     * virar demanda no Funil de Demandas) e o resultado congelado no encerramento.
     */
    public function up(): void
    {
        Schema::create('sprint_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sprint')->unique();
            $table->string('sprint_name');
            $table->string('status', 10)->default('open');
            $table->foreignId('facilitator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('step')->default(0);
            $table->timestamp('step_started_at')->nullable();
            $table->json('step_seconds')->nullable();
            // Entrega que está no palco da demonstração (Id do card no Monday).
            $table->string('current_item_id', 20)->nullable();
            // Objetivo da sprint atingido? yes, partial ou no, e por quê.
            $table->string('goal_result', 10)->nullable();
            $table->text('goal_note')->nullable();
            // Indicadores e lista de entregas congelados no encerramento.
            $table->json('metrics')->nullable();
            $table->json('items')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sprint_review_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sprint_review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['sprint_review_id', 'user_id']);
        });

        // Decisão sobre cada card: aceite das entregas ou motivo e destino dos planejados não entregues.
        Schema::create('sprint_review_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sprint_review_id')->constrained()->cascadeOnDelete();
            $table->string('item_id', 20);
            $table->string('verdict', 10)->nullable();
            $table->text('notes')->nullable();
            $table->string('reason', 20)->nullable();
            $table->string('destination', 10)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['sprint_review_id', 'item_id']);
        });

        Schema::create('sprint_review_feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sprint_review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('given_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 12);
            $table->text('text');
            $table->string('item_id', 20)->nullable();
            $table->string('item_name')->nullable();
            // Demanda criada no Funil de Demandas: null (não pedida), sending, created ou failed.
            $table->string('demand_status', 10)->nullable();
            $table->string('demand_item_id', 20)->nullable();
            $table->string('demand_url', 500)->nullable();
            $table->text('demand_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sprint_review_feedbacks');
        Schema::dropIfExists('sprint_review_items');
        Schema::dropIfExists('sprint_review_participants');
        Schema::dropIfExists('sprint_reviews');
    }
};
