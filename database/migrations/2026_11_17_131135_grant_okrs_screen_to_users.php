<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Quem já enxerga o Dashboard passa a enxergar os OKRs do time, que usam os mesmos indicadores. Precisa entrar
     * junto com o código que conhece a tela (App\Screen::Okrs): sem ele, o valor gravado quebra a leitura das telas.
     */
    public function up(): void
    {
        $this->eachUserWithScreen('dashboard', fn (array $screens): array => in_array('okrs', $screens, true) ? $screens : [...$screens, 'okrs']);
    }

    public function down(): void
    {
        $this->eachUserWithScreen('okrs', fn (array $screens): array => array_values(array_diff($screens, ['okrs'])));
    }

    /** @param  callable(list<string>): list<string>  $change */
    private function eachUserWithScreen(string $screen, callable $change): void
    {
        DB::table('users')->whereNotNull('screens')->orderBy('id')->each(function (object $user) use ($screen, $change): void {
            $screens = json_decode($user->screens, true) ?: [];
            if (in_array($screen, $screens, true)) {
                DB::table('users')->where('id', $user->id)->update(['screens' => json_encode($change($screens))]);
            }
        });
    }
};
