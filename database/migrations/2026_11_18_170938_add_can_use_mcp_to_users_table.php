<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permissão "Acesso ao MCP": só quem a tem autoriza uma ferramenta de IA a consultar o Metrics. Começa desligada para todos.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_use_mcp')->default(false)->after('can_estimate_planning');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_use_mcp');
        });
    }
};
