<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O compromisso da sprint passa a vir somente da coluna Planejada do Monday: o dashboard é apenas de acompanhamento.
 * As marcações locais são descartadas; o rollback recria a tabela vazia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('sprint_commitments');
    }

    public function down(): void
    {
        Schema::create('sprint_commitments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sprint');
            $table->string('item_id', 32);
            $table->boolean('committed');
            $table->timestamps();

            $table->unique(['sprint', 'item_id']);
        });
    }
};
