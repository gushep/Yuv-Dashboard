<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Quem já enxerga a torre de controle passa a enxergar o burndown, que usa a mesma trajetória da sprint.
     */
    public function up(): void
    {
        $this->eachUserWithScreen('control-tower', fn (array $screens): array => in_array('burndown', $screens, true) ? $screens : [...$screens, 'burndown']);
    }

    public function down(): void
    {
        $this->eachUserWithScreen('burndown', fn (array $screens): array => array_values(array_diff($screens, ['burndown'])));
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
