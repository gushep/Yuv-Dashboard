<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monday_connections', function (Blueprint $table) {
            $table->json('metric_settings')->nullable()->after('forecast_settings');
        });

        // Preserva a regra que estava fixa no código: o QA do time ficava fora das métricas individuais.
        DB::table('monday_connections')->whereNull('metric_settings')->update([
            'metric_settings' => json_encode(['capacityHours' => 80, 'excludedPeople' => ['Victor Hugo Teodoro Pimentel']], JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function down(): void
    {
        Schema::table('monday_connections', function (Blueprint $table) {
            $table->dropColumn('metric_settings');
        });
    }
};
