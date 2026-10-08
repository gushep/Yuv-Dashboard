<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('screens')->nullable()->after('password');
        });

        // Quem já usava o dashboard enxergava todas as telas; mantém esse acesso, inclusive o cadastro de usuários.
        DB::table('users')->whereNull('screens')->update([
            'screens' => json_encode([
                'control-tower', 'dashboard', 'sprint', 'epicos', 'time', 'raw', 'bombeiro', 'sprint-panel',
                'carousel', 'capacity', 'forecast', 'consumption', 'release', 'settings', 'users',
            ]),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('screens');
        });
    }
};
