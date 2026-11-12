<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily Scrum: uma Daily por sprint e dia; as ações (To-Do cumulativo) registradas nela e o histórico de cada ação
     * (aberta, concluída, reaberta, publicada no Monday). Uma ação pendente segue aparecendo nas próximas Dailies da
     * sprint sem ser duplicada: ela fica sempre ligada à Daily em que foi aberta.
     */
    public function up(): void
    {
        Schema::create('daily_scrums', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sprint');
            $table->date('date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['sprint', 'date']);
        });

        Schema::create('daily_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_scrum_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sprint')->index();
            $table->text('description');
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            // Card do Monday vinculado (opcional): o Monday continua a fonte do card; aqui fica só a referência.
            $table->string('item_id', 20)->nullable();
            $table->string('item_name')->nullable();
            $table->string('item_url', 500)->nullable();
            // Aviso de abertura no card (comentário "pendente"): null (não enviado), sending, published ou failed.
            $table->string('notice_status', 10)->nullable();
            $table->string('notice_update_id', 30)->nullable();
            $table->string('status', 10)->default('pending');
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            // Publicação da conclusão atual no card: null (não pedida), pending, published ou failed.
            $table->string('publish_status', 10)->nullable();
            $table->string('monday_update_id', 30)->nullable();
            $table->timestamps();
            $table->index(['sprint', 'status']);
        });

        Schema::create('daily_action_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_action_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->string('monday_update_id', 30)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['daily_action_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_action_events');
        Schema::dropIfExists('daily_actions');
        Schema::dropIfExists('daily_scrums');
    }
};
