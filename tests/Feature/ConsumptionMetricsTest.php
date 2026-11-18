<?php

namespace Tests\Feature;

use App\Metrics\Consumption;
use App\Metrics\MetricsData;
use App\Support\SprintRules;
use Tests\TestCase;

/**
 * Paridade com public/consumption.js: os mesmos dados e valores esperados de tests/consumption.tests.html.
 */
class ConsumptionMetricsTest extends TestCase
{
    private const TEAM = ['team' => [['id' => '1', 'name' => 'Ana', 'group' => 'fullstack'], ['id' => '2', 'name' => 'Bia', 'group' => 'projects']]];

    public function test_each_card_counts_once_with_its_type_whatever_the_status(): void
    {
        $result = (new Consumption(SprintRules::teamRules([])))->model($this->rows(), 17);

        $this->assertSame([1110, 360, 750, 7], [$result['macro']['total'], $result['macro']['sprint'], $result['macro']['support'], count($result['macro']['tasks'])]);
        $this->assertSame(360, $result['unassigned']['total']);
        $this->assertSame(750, $result['attributed']['total']);
        $this->assertSame(630, collect($result['developers'])->firstWhere('name', 'Ana')['total']);
        $this->assertSame($result['macro']['sprint'], $result['attributed']['sprint'] + $result['unassigned']['sprint']);
        $this->assertSame($result['macro']['support'], $result['attributed']['support'] + $result['unassigned']['support']);
        $this->assertSame([1, 1, 1, 1], [$result['missingType'], $result['missingReal'], $result['missingId'], $result['multiSprint']]);
        $this->assertEqualsWithDelta(750 / 1110 * 100, Consumption::shares($result['macro'])['support'], 1e-9);
    }

    public function test_teams_split_development_projects_and_people_without_team(): void
    {
        $consumption = new Consumption(SprintRules::teamRules(self::TEAM));
        $byTeam = $consumption->model([$this->row('a', 2, 'Melhoria', 'Ana'), $this->row('b', 3, 'Suporte', 'Bia'), $this->row('c', 4, 'Melhoria', 'QA'), $this->row('d', 1, 'Melhoria', 'Ana, QA')], 17);

        $this->assertSame([600, 180, 180, 240], [$byTeam['macro']['total'], $byTeam['teams']['fullstack']['total'], $byTeam['teams']['projects']['total'], $byTeam['outside']['total']]);
        $this->assertSame(180, collect($byTeam['developers'])->firstWhere('name', 'Ana')['total']);
        $this->assertSame('Desenvolvimento 3h · Projetos 3h · Sem time 4h', Consumption::compositionText($byTeam, 'total'));
        $this->assertSame('Desenvolvimento 0h · Projetos 3h', Consumption::compositionText($byTeam, 'support'));
    }

    public function test_durations_formats_and_empty_totals(): void
    {
        $this->assertSame([450, 450, 450, 30, null, null], [Consumption::duration('7h30'), Consumption::duration('7,5'), Consumption::duration('7:30'), Consumption::duration('30m'), Consumption::duration(''), Consumption::duration('7h90')]);
        $this->assertSame(['7h30', '12h30', 'Sem registro'], [Consumption::format(450), Consumption::format(750), Consumption::format(null)]);
        $this->assertSame(['sprint' => null, 'support' => null], Consumption::shares(['total' => 0, 'sprint' => 0, 'support' => 0]));
        $this->assertSame(['125%', '67,6%', '—'], [Consumption::percent(125), Consumption::percent(750 / 1110 * 100), Consumption::percent(null)]);
    }

    public function test_support_reserve_consumption(): void
    {
        $over = Consumption::reserveModel(3000, 2400);
        $this->assertEquals([125, 25], [$over['consumed'], $over['excess']]);
        $equal = Consumption::reserveModel(2400, 2400);
        $this->assertEquals([0, 100], [$equal['balance'], $equal['consumed']]);
        $under = Consumption::reserveModel(1200, 2400);
        $this->assertEquals([1200, 50], [$under['balance'], $under['available']]);
        $this->assertNull(Consumption::reserveModel(3000, 0)['consumed']);
        $this->assertNull(Consumption::reserveModel(0, 0)['consumed']);
        // Sem reserva: "12h30 consumidas sem reserva".
        $this->assertSame(-750, Consumption::reserveModel(750, 0)['balance']);
    }

    public function test_reserve_pace_warns_when_the_reserve_ends_before_the_sprint(): void
    {
        $dates = ['start' => '2026-09-28', 'end' => '2026-10-09'];

        $fast = Consumption::reservePace(30 * 60, $dates, 40 * 60, '2026-10-02');
        $this->assertTrue($fast['risk']);
        $this->assertSame('No ritmo atual (6h por dia útil): 60h até o fim da sprint, 150% da reserva; a reserva acaba em 06/10, antes do fim da sprint (09/10).', $fast['text']);
        $this->assertFalse(Consumption::reservePace(5 * 60, $dates, 40 * 60, '2026-10-02')['risk']);
        $this->assertSame('Ritmo indisponível: datas da sprint ausentes no Monday.', Consumption::reservePace(60, null, 600, '2026-10-02')['text']);
    }

    public function test_screen_cards_follow_capacity_with_support_reserve(): void
    {
        $dates = [17 => ['number' => 17, 'start' => '2026-09-28', 'end' => '2026-10-11']];
        $data = new MetricsData(rows: [$this->row('a', 2, 'Melhoria', 'Ana'), $this->row('b', 3, 'Melhoria', 'Bia'), $this->row('c', 4, 'Melhoria', 'QA')],
            sprintDates: $dates, settings: self::TEAM, supportReserves: ['17' => 2400], today: '2026-10-07');

        $screen = Consumption::for($data)->sprint($data, 17);
        $this->assertSame('9h', Consumption::format($screen['model']['macro']['total']));
        $this->assertStringContainsString('Sem time 4h', $screen['composition']['total']);
        $this->assertSame([9600, 2400, 2400, 4800], array_column($screen['summary']['cards'], 'capacity'));
        $this->assertSame([300, 120, 0, 180], array_column($screen['summary']['cards'], 'registered'));
        $this->assertSame(['estimate' => 0, 'registered' => 240], $screen['summary']['outside']);
        $this->assertSame(['green', 'green', 'green', 'green'], array_column($screen['summary']['cards'], 'registeredLevel'));
        $this->assertSame([17], Consumption::sprints($data));

        $heavy = new MetricsData(rows: [...$data->rows, $this->row('e', 45, 'Suporte', 'Ana'), $this->row('f', 100, 'Melhoria', 'Ana')], sprintDates: $dates, settings: self::TEAM, supportReserves: ['17' => 2400], today: '2026-10-07');
        $cards = Consumption::for($heavy)->sprint($heavy, 17)['summary']['cards'];
        $this->assertSame(['yellow', 'red', 'red', 'green'], array_column($cards, 'registeredLevel'));
        $this->assertSame(9000, $cards[0]['registered']);
        $this->assertSame(-300, $cards[2]['capacity'] - $cards[2]['registered']);
        $this->assertSame(['green', 'green', 'green', 'green'], array_column($cards, 'estimateLevel'));
    }

    /**
     * Os cards de tests/consumption.tests.html: duplicado, Tipo com espaços, sem Tipo, compartilhado, sem responsável,
     * sem registro, zero, outra sprint, repetido em sprint anterior e sem Id.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return [
            $this->row('1', '7h30', '  SUporte  '), $this->row('1', '7h30', '  SUporte  '), $this->row('2', 2, 'Bug', 'Bia'), $this->row('3', 3, '', 'Ana'),
            $this->row('4', 5, 'Suporte', 'Ana, Bia'), $this->row('5', 1, 'Melhoria', ''), $this->row('6', null), $this->row('7', 0),
            $this->row('8', 100, 'Suporte', 'Ana', 18), $this->row('2', 2, 'Bug', 'Bia', 16), $this->row('', 100),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $id, int|float|string|null $real, string $type = 'Melhoria', string $owner = 'Ana', int $sprint = 17): array
    {
        return ['Id' => $id, 'RealH' => $real, 'Tipo' => $type, 'Responsavel' => $owner, 'Sprint' => $sprint, 'Nome' => "Tarefa {$id}", 'Status' => 'Implementado', 'Planejada' => 'Não planejada'];
    }
}
