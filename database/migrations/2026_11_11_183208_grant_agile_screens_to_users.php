<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Telas novas do menu Gerenciamento Ágil (ainda em construção). */
    private const AGILE_SCREENS = ['daily-scrum', 'sprint-review', 'sprint-retrospective'];

    /**
     * Quem já enxerga as telas de Gerenciamento Ágil de hoje (Sprints ou Sprint Planning) passa a enxergar também as
     * telas novas do mesmo menu: Daily Scrum, Sprint Review e Sprint Retrospective.
     */
    public function up(): void
    {
        $this->eachUser(function (array $screens): array {
            if (array_intersect(['sprint', 'planning'], $screens) === []) {
                return $screens;
            }

            return array_values(array_unique([...$screens, ...self::AGILE_SCREENS]));
        });
    }

    public function down(): void
    {
        $this->eachUser(fn (array $screens): array => array_values(array_diff($screens, self::AGILE_SCREENS)));
    }

    /** @param  callable(list<string>): list<string>  $change */
    private function eachUser(callable $change): void
    {
        DB::table('users')->whereNotNull('screens')->orderBy('id')->each(function (object $user) use ($change): void {
            $screens = json_decode($user->screens, true) ?: [];
            $changed = $change($screens);
            if ($changed !== $screens) {
                DB::table('users')->where('id', $user->id)->update(['screens' => json_encode($changed)]);
            }
        });
    }
};
