<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rodadas da planning poker: um card do Backlog do quadro Tarefas por rodada, dentro da sessão da sprint. O resultado
     * vai para o card no Monday; aqui fica só o andamento (leitura, votação, revelação) e o registro de quem decidiu.
     */
    public function up(): void
    {
        Schema::create('planning_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planning_session_id')->constrained()->cascadeOnDelete();
            $table->string('item_id', 20);
            $table->string('item_name');
            $table->string('item_url', 500)->nullable();
            $table->string('item_owner')->nullable();
            $table->decimal('ai_estimate', 8, 2)->nullable();
            $table->string('scope_title')->nullable();
            $table->longText('scope_html')->nullable();
            $table->string('status', 10)->default('reading');
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('average', 8, 2)->nullable();
            $table->decimal('final_estimate', 8, 2)->nullable();
            $table->string('final_source', 10)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->timestamp('revealed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_rounds');
    }
};
