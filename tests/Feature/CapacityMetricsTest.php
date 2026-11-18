<?php

namespace Tests\Feature;

use App\Metrics\Capacity;
use App\Metrics\HoursStrip;
use App\Metrics\MetricsData;
use App\Support\SprintRules;
use Tests\TestCase;

/**
 * Paridade com public/capacity.js, public/hours-strip.js e public/sprint-rules.js: os mesmos dados e valores esperados
 * de tests/capacity.tests.html, tests/hours-strip.tests.html e tests/sprint-rules.tests.html.
 */
class CapacityMetricsTest extends TestCase
{
    private const TEAM = ['team' => [['id' => '1', 'name' => 'Ana', 'group' => 'fullstack'], ['id' => '2', 'name' => 'Bruno', 'group' => 'fullstack'], ['id' => '3', 'name' => 'Carla', 'group' => 'projects']]];

    private const DATES = ['start' => '2026-09-28', 'end' => '2026-10-09'];

    public function test_support_card_sums_support_of_any_planning_and_owner(): void
    {
        $this->assertSame(['cards' => 3, 'registered' => 540, 'estimate' => 600], Capacity::supportTotals($this->capacityRows(), 17));
    }

    public function test_team_totals_leave_support_and_cancelled_cards_out_and_inherit_hours(): void
    {
        $capacity = new Capacity(SprintRules::teamRules(self::TEAM));
        $model = $capacity->model($this->capacityRows(), 17, self::DATES, ['16|Carla' => 2400], '2026-10-07');
        $totals = $capacity->teamTotals($model['developers'], 480);

        $this->assertSame(['people' => 2, 'capacity' => 9600, 'net' => 9120, 'estimate' => 600, 'registered' => 240], $totals['fullstack']);
        $this->assertSame(['people' => 1, 'capacity' => 2400, 'estimate' => 2220, 'registered' => 600], $totals['projects']);
        $this->assertSame(['estimate' => 240, 'registered' => 240], $capacity->outsideTotals($model));
        $carla = collect($model['developers'])->firstWhere('name', 'Carla');
        $this->assertSame(16, $carla['inheritedFrom']);
        $this->assertSame(['Ana', 'Bruno', 'Carla'], array_column($model['developers'], 'name'));
    }

    public function test_summary_cards_show_estimate_and_registered_against_each_capacity_with_traffic_light(): void
    {
        $summary = (new Capacity(SprintRules::teamRules(self::TEAM)))->summaryFor($this->capacityRows(), 17, self::DATES, ['16|Carla' => 2400], 480, '2026-10-07');
        $cards = $summary['cards'];

        $this->assertSame(['Capacidade da sprint', 'Full stack para a sprint', 'Bombeiro (sustentação)', 'Projetos (IA)'], array_column($cards, 'label'));
        $this->assertSame([12000, 9120, 480, 2400], array_column($cards, 'capacity'));
        $this->assertSame([3420, 600, 600, 2220], array_column($cards, 'estimate'));
        $this->assertSame([1380, 240, 540, 600], array_column($cards, 'registered'));
        $this->assertSame(['green', 'green', 'red', 'yellow'], array_column($cards, 'estimateLevel'));
        $this->assertSame(['green', 'green', 'red', 'green'], array_column($cards, 'registeredLevel'));
        // Caixinha "Disponível": 143h, 142h, Excedido 2h, 3h no previsto; 177h, 148h, Excedido 1h, 30h no registrado.
        $this->assertSame(['143h', '142h', '-2h', '3h'], array_map(fn (array $card): string => Capacity::formatMinutes($card['capacity'] - $card['estimate']), $cards));
        $this->assertSame(['177h', '148h', '-1h', '30h'], array_map(fn (array $card): string => Capacity::formatMinutes($card['capacity'] - $card['registered']), $cards));
        $this->assertSame(['estimate' => 240, 'registered' => 240], $summary['outside']);
    }

    public function test_traffic_light_limits(): void
    {
        $this->assertSame(['green', 'yellow', 'yellow', 'red', 'green', 'red'], [
            Capacity::capacityLevel(89, 100), Capacity::capacityLevel(90, 100), Capacity::capacityLevel(99, 100),
            Capacity::capacityLevel(100, 100), Capacity::capacityLevel(0, 0), Capacity::capacityLevel(5, 0),
        ]);
    }

    public function test_hours_strip_table_matches_the_top_bar(): void
    {
        $data = new MetricsData(
            rows: [$this->row('1', 'Ana', 'Melhoria', 'Planejada', 20, 10), $this->row('2', 'Ana', 'Suporte', 'Planejada', 5, 9), $this->row('3', 'Ana', 'Melhoria', 'Planejada', 4, 4, sprint: 16)],
            sprintDates: [16 => ['start' => '2026-09-14', 'end' => '2026-09-25'], 17 => self::DATES],
            settings: ['team' => [['id' => '1', 'name' => 'Ana', 'group' => 'fullstack']]],
            supportReserves: ['17' => 480],
            today: '2026-10-07',
        );

        $columns = HoursStrip::table($data, 17)['columns'];
        $cell = fn (string $key): array => array_map(fn (array $column): string => Capacity::formatMinutes($column[$key]['used']).' de '.Capacity::formatMinutes($column['capacity']).' · '.($column[$key]['percent'] === null ? '—' : $column[$key]['percent'].'%'), $columns);
        $available = fn (string $key): array => array_map(fn (array $column): string => $column[$key]['available'] < 0 ? Capacity::formatMinutes(-$column[$key]['available']).' excedido' : Capacity::formatMinutes($column[$key]['available']), $columns);

        $this->assertSame(['Capacidade', 'Full stack', 'Bombeiro', 'Projetos'], array_column($columns, 'shortLabel'));
        $this->assertSame(['25h de 80h · 31%', '20h de 72h · 28%', '5h de 8h · 63%', '0h de 0h · —'], $cell('planned'));
        $this->assertSame(['55h', '52h', '3h', '0h'], $available('planned'));
        $this->assertSame(['19h de 80h · 24%', '10h de 72h · 14%', '9h de 8h · 113%', '0h de 0h · —'], $cell('realized'));
        $this->assertSame(['61h', '62h', '1h excedido', '0h'], $available('realized'));
        $this->assertSame(['green', 'green', 'green', 'green'], array_column(array_column($columns, 'planned'), 'level'));
        $this->assertSame(['green', 'green', 'red', 'green'], array_column(array_column($columns, 'realized'), 'level'));
        // Sprint 16, sem reserva salva: vale o padrão; o planejado é 4h de 80h.
        $this->assertSame(240, HoursStrip::table($data, 16)['columns'][0]['planned']['used']);
        $this->assertSame(5, HoursStrip::table($data, 16)['columns'][0]['planned']['percent']);
        $this->assertSame(4800, HoursStrip::reserveFor($data, 16));
    }

    public function test_capacity_rules_mirror_sprint_rules_js(): void
    {
        $this->assertSame(4800, (new Capacity(SprintRules::teamRules([])))->defaultMinutes());
        $configured = SprintRules::teamRules(['capacityHours' => '60', 'excludedPeople' => ['Ana Souza']]);
        $this->assertSame(3600, (new Capacity($configured))->defaultMinutes());
        $this->assertNull($configured->teamOf(' ana souza '));
        $this->assertSame('fullstack', $configured->teamOf('Bia'));
        $teams = SprintRules::teamRules(['team' => [['id' => '1', 'name' => 'Bia Lima', 'group' => 'fullstack'], ['id' => '2', 'name' => 'Caio', 'group' => 'projects'], ['id' => '3', 'name' => 'Dani', 'group' => 'qa']]]);
        $this->assertSame(['fullstack', 'projects', null, null], [$teams->teamOf(' bia lima '), $teams->teamOf('Caio'), $teams->teamOf('Dani'), $teams->teamOf('Ana Souza')]);
        $this->assertCount(2, $teams->members());

        $overrides = ['18|Caio' => 2400, '21|Caio' => 600, '17|Bia Lima' => 4000];
        $this->assertSame(['minutes' => 2400, 'inherited' => true, 'fromSprint' => 18], SprintRules::capacityFor($overrides, 20, 'Caio', 3600));
        $this->assertSame(['minutes' => 600, 'inherited' => false, 'fromSprint' => 21], SprintRules::capacityFor($overrides, 21, 'caio', 3600));
        $this->assertSame(3600, SprintRules::capacityFor($overrides, 17, 'Caio', 3600)['minutes']);
        $this->assertTrue(SprintRules::isClosed('Implementado') && SprintRules::isClosed('Cancelado') && ! SprintRules::isClosed('Aguardando Deploy'));
    }

    public function test_pace_projects_consumption_until_the_end_of_the_sprint(): void
    {
        $half = SprintRules::pace(20 * 60, self::DATES, '2026-10-02', 40 * 60);
        $this->assertSame(['ongoing', 5, 10, 2400, 100], [$half['status'], $half['elapsedDays'], $half['totalDays'], $half['projected'], SprintRules::round($half['projectedPercent'])]);
        $fast = SprintRules::pace(30 * 60, self::DATES, '2026-10-02', 40 * 60);
        $this->assertSame('2026-10-06', $fast['exhaustionDate']);
        $this->assertLessThan($fast['sprintEnd'], $fast['exhaustionDate']);
        $this->assertNull(SprintRules::pace(5 * 60, self::DATES, '2026-10-02', 40 * 60)['exhaustionDate']);
        $this->assertSame('not-started', SprintRules::pace(60, self::DATES, '2026-09-27', 600)['status']);
        $this->assertSame('unavailable', SprintRules::pace(60, null, '2026-10-01', 600)['status']);
    }

    public function test_hours_conversion_and_capacity_until_today(): void
    {
        $this->assertSame([450, 450, 420, 30, 450, 450, null, null, 0], [
            Capacity::minutes('7h30'), Capacity::minutes('7h 30m'), Capacity::minutes('7h'), Capacity::minutes('30m'),
            Capacity::minutes('7:30'), Capacity::minutes('7,5'), Capacity::minutes(''), Capacity::minutes('abc'), Capacity::minutes(0),
        ]);
        $this->assertSame(['7h', '7h30', '-1h05', '—'], [Capacity::formatMinutes(420), Capacity::formatMinutes(450), Capacity::formatMinutes(-65), Capacity::formatMinutes(null)]);
        $this->assertSame(10, Capacity::weekdays('2026-09-28', '2026-10-09'));
        $this->assertSame([0, 2400, 4800, null], [
            Capacity::elapsedMinutes(4800, self::DATES, '2026-09-27'), Capacity::elapsedMinutes(4800, self::DATES, '2026-10-02'),
            Capacity::elapsedMinutes(4800, self::DATES, '2026-10-09'), Capacity::elapsedMinutes(4800, null, '2026-10-02'),
        ]);
    }

    /**
     * Os cards de tests/capacity.tests.html: Ana, Bruno e Carla com time; QA sem time; um card sem responsável e um
     * cancelado.
     *
     * @return list<array<string, mixed>>
     */
    private function capacityRows(): array
    {
        return [
            $this->row('1', 'Ana', 'Melhoria', 'Planejada', 10, 4),
            $this->row('2', 'Ana', 'Suporte', 'Planejada', 3, 2),
            $this->row('3', 'Bruno', 'Suporte', 'Não planejada', 5, 6),
            $this->row('4', 'Carla', 'Melhoria', 'Planejada', 37, 10),
            $this->row('5', '', 'Suporte', 'Não planejada', 2, 1),
            $this->row('6', 'QA', 'Melhoria', 'Planejada', 4, 4),
            $this->row('7', 'Ana', 'Melhoria', 'Planejada', 8, 0, 'Cancelado'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $id, string $owner, string $type, string $planned, int|float|string|null $estimate, int|float|string|null $real, string $status = 'Em progresso', int $sprint = 17): array
    {
        return ['Id' => $id, 'Nome' => "Card {$id}", 'Responsavel' => $owner, 'Tipo' => $type, 'Planejada' => $planned, 'EstH' => $estimate, 'RealH' => $real, 'Status' => $status, 'Sprint' => $sprint];
    }
}
