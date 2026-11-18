<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auditoria do MCP: cada chamada de uma ferramenta de IA, com quem pediu, qual ferramenta e o resultado.
     */
    public function up(): void
    {
        Schema::create('mcp_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client_id', 100)->nullable();
            $table->string('client_name')->nullable();
            $table->string('method', 60);
            $table->string('tool', 100)->nullable();
            $table->unsignedSmallInteger('status');
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_audit_logs');
    }
};
