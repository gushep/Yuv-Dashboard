<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tempo da Daily pelos botões Iniciar e Encerrar, como nas outras cerimônias. A Daily pode ser retomada: o tempo de
     * cada trecho vai somando em `open_seconds` (o trecho atual começa em `opened_at`). Dailies sem `started_at` são
     * anteriores aos botões e seguem com o tempo pela presença na tela.
     */
    public function up(): void
    {
        Schema::table('daily_scrums', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('date');
            $table->foreignId('started_by')->nullable()->after('started_at')->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->nullable()->after('started_by');
            $table->unsignedInteger('open_seconds')->default(0)->after('opened_at');
            $table->timestamp('closed_at')->nullable()->after('open_seconds');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('daily_scrums', function (Blueprint $table) {
            $table->dropConstrainedForeignId('started_by');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['started_at', 'opened_at', 'open_seconds', 'closed_at']);
        });
    }
};
