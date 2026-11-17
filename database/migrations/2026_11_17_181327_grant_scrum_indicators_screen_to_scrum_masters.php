<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Quem já é Maestro Scrum (conduz a planning) passa a enxergar os Indicadores Scrum. Precisa entrar junto com o
     * código que conhece a tela (App\Screen::ScrumIndicators): sem ele, o valor gravado quebra a leitura das telas.
     */
    public function up(): void
    {
        DB::table('users')->where('can_conduct_planning', true)->orderBy('id')->each(function (object $user): void {
            $screens = json_decode((string) $user->screens, true) ?: [];
            if (! in_array('scrum-indicators', $screens, true)) {
                DB::table('users')->where('id', $user->id)->update(['screens' => json_encode([...$screens, 'scrum-indicators'])]);
            }
        });
    }

    public function down(): void
    {
        DB::table('users')->whereNotNull('screens')->orderBy('id')->each(function (object $user): void {
            $screens = json_decode($user->screens, true) ?: [];
            if (in_array('scrum-indicators', $screens, true)) {
                DB::table('users')->where('id', $user->id)->update(['screens' => json_encode(array_values(array_diff($screens, ['scrum-indicators'])))]);
            }
        });
    }
};
