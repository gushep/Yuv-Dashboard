<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quando a mudança de status foi tratada pelo aviso do canal do Bombeiro (avisada ou descartada).
     */
    public function up(): void
    {
        Schema::table('monday_activity_events', function (Blueprint $table) {
            $table->dateTime('notified_at')->nullable();
            $table->index(['board_id', 'column_id', 'notified_at']);
        });
    }

    public function down(): void
    {
        Schema::table('monday_activity_events', function (Blueprint $table) {
            $table->dropIndex(['board_id', 'column_id', 'notified_at']);
            $table->dropColumn('notified_at');
        });
    }
};
