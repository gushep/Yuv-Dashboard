<?php

namespace Tests\Feature;

use App\Metrics\MetricsData;
use App\Metrics\Okrs;
use Tests\TestCase;

/**
 * Paridade com tests/okrs.tests.html: os mesmos dados de exemplo (Ana e Bruno no Full stack, 80h cada, reserva do
 * Bombeiro de 16h por sprint, hoje 17/11/2026) e os mesmos valores que a tela mostra.
 */
class OkrsParityTest extends TestCase
{
    public function test_key_result_levels_follow_the_target_bands(): void
    {
        $this->assertSame(['green', 'yellow', 'red'], array_map(fn (float $value): string => Okrs::level('productivity', $value), [90, 85, 84.9]));
        $this->assertSame(['green', 'yellow', 'red'], array_map(fn (float $value): string => Okrs::level('rework', $value), [10, 15, 15.1]));
        $this->assertSame(['green', 'yellow', 'red'], array_map(fn (float $value): string => Okrs::level('backlog', $value), [80, 75, 74.9]));
        $this->assertSame('none', Okrs::level('backlog', null));
    }

    public function test_current_sprint_key_results_and_the_unstarted_sprint_out_of_the_filter(): void
    {
        $okrs = new Okrs($this->data());
        $result = $okrs->period(20, 20);

        $this->assertSame([17, 18, 19, 20], $okrs->startedSprints());
        $this->assertTrue($result['partial']);
        $this->assertSame([50.0, 0.0, 25.0], $this->values($result['results']));
        $this->assertSame(['red', 'green', 'red'], $this->levels($result['results']));
        $this->assertSame([2, 4], [$result['results']['productivity']['part'], $result['results']['productivity']['whole']]);
        $this->assertSame([0, 4], [$result['results']['rework']['part'], $result['results']['rework']['whole']]);
        $this->assertSame([40 * 60, 160 * 60], [$result['results']['backlog']['part'], $result['results']['backlog']['whole']]);
    }

    public function test_interval_sums_the_period_and_splits_the_backlog_in_fullstack_support_and_projects(): void
    {
        $result = (new Okrs($this->data()))->period(19, 18);

        $this->assertSame([18, 19], $result['sprints']);
        $this->assertFalse($result['partial']);
        $this->assertSame([88.2, 11.8, 82.2], $this->values($result['results']));
        // Atingimento como na tela: 88,2 ÷ 90 = 98%; retrabalho 11,8% = 82%; backlog acima da meta = 100%. Progresso = média.
        $this->assertSame([98.0, 82.0, 100.0], array_column($result['results'], 'attainment'));
        $this->assertSame(93.3, $result['progress']);
        $this->assertSame(['yellow', 'yellow', 'green'], $this->levels($result['results']));
        $this->assertSame([263 * 60, 320 * 60], [$result['results']['backlog']['part'], $result['results']['backlog']['whole']]);
        $parts = array_map(fn (array $part): array => [$part['kind'], $part['value'], $part['part'] / 60, $part['whole'] / 60, $part['level']], $result['results']['backlog']['parts']);
        $this->assertEquals([['fullstack', 88.5, 255, 288, 'green'], ['support', 25.0, 8, 32, 'red'], ['projects', null, 0, 0, 'none']], $parts);

        $sprint19 = collect($result['perSprint'])->firstWhere('sprint', 19)['results'];
        $this->assertSame(91.7, $sprint19['productivity']['value']);
        $this->assertSame([11, 12], [$sprint19['productivity']['part'], $sprint19['productivity']['whole']]);
        $this->assertSame([86.3, 138 * 60, 160 * 60], [$sprint19['backlog']['value'], $sprint19['backlog']['part'], $sprint19['backlog']['whole']]);
    }

    public function test_months_and_growth_with_the_summary_against_the_previous_closed_sprint(): void
    {
        $result = (new Okrs($this->data()))->period(18, 19);

        $this->assertSame(['2026-11', '2026-10', '2026-09'], array_column($result['months'], 'month'));
        $this->assertSame([[20], [18, 19], [17]], array_column($result['months'], 'sprints'));
        $this->assertTrue($result['months'][0]['partial']);
        $this->assertSame([88.2, 11.8, 82.2], $this->values($result['months'][1]['results']));

        $growth = array_map(fn (array $item): string => "S{$item['sprint']}:{$item['planned']}/{$item['unplanned']}/{$item['support']}={$item['total']}·{$item['done']}", $result['growth']);
        $this->assertSame(['S17:2/3/0=5·3', 'S18:5/0/0=5·4', 'S19:10/0/2=12·11', 'S20:4/0/0=4·2'], $growth);
        $this->assertSame('Sprint 19 contra a 18: 11 entregas por sprint contra 4 (+175%) e 12 cards registrados contra 5 (+140%).', $result['growthSummary']);
    }

    public function test_per_person_results_parts_and_daily_xp(): void
    {
        $okrs = new Okrs($this->data());
        [$ana, $bruno] = $okrs->period(18, 19)['people'];

        $this->assertSame(['Ana', 'Bruno'], [$ana['name'], $bruno['name']]);
        $this->assertSame([88.2, 11.8, 164.4], $this->values($ana['results']));
        $this->assertSame(['yellow', 'yellow', 'green'], $this->levels($ana['results']));
        $this->assertSame([15, 17], [$ana['results']['productivity']['part'], $ana['results']['productivity']['whole']]);
        $this->assertSame([263 * 60, 160 * 60], [$ana['results']['backlog']['part'], $ana['results']['backlog']['whole']]);
        $this->assertEquals([['fullstack', 177.1, 255, 144], ['support', 50.0, 8, 16]], $this->parts($ana['results']['backlog']['parts']));
        $this->assertSame(['delivered' => 15, 'onTarget' => 15, 'early' => 0, 'xp' => 225, 'precision' => 100], $ana['score']);

        $this->assertSame([null, null, 0.0], $this->values($bruno['results']));
        $this->assertSame(['none', 'none', 'red'], $this->levels($bruno['results']));
        $this->assertEquals([['fullstack', 0.0, 0, 144], ['support', 0.0, 0, 16]], $this->parts($bruno['results']['backlog']['parts']));
        $this->assertSame(0, $bruno['score']['xp']);

        $perSprint = array_map(fn (int $sprint): array => $this->values($okrs->people([$sprint])[0]['results']), [19, 18]);
        $this->assertSame([[91.7, 8.3, 172.5], [80.0, 20.0, 156.3]], $perSprint);
    }

    public function test_projects_person_has_projects_and_support_parts_without_firefighter_reserve(): void
    {
        $data = new MetricsData(
            rows: [
                ['Sprint' => 19, 'Responsavel' => 'Carla', 'Status' => 'Implementado', 'Planejada' => 'Planejada', 'Tipo' => 'Melhoria', 'EstH' => 9, 'RealH' => 9],
                ['Sprint' => 19, 'Responsavel' => 'Carla', 'Status' => 'Implementado', 'Planejada' => 'Não Planejado', 'Tipo' => 'Suporte', 'EstH' => 1, 'RealH' => 1],
            ],
            sprintDates: [19 => ['start' => '2026-10-27', 'end' => '2026-11-07']],
            settings: ['capacityHours' => 80, 'team' => [['id' => '3', 'name' => 'Carla', 'group' => 'projects']]],
            supportReserves: ['19' => 16 * 60],
            today: '2026-11-17',
        );

        $carla = (new Okrs($data))->people([19])[0];

        $this->assertSame('projects', $carla['team']);
        $this->assertEquals([['projects', 11.3, 9, 80], ['support', null, 1, 0]], $this->parts($carla['results']['backlog']['parts']));
    }

    public function test_infographic_numbers_highlights_and_trend_against_the_previous_period(): void
    {
        $result = (new Okrs($this->data()))->period(20, 20);
        $info = $result['infographic'];

        $this->assertSame(1, $info['hit']);
        $this->assertSame(['cards' => 4, 'done' => 2, 'rework' => 0, 'registered' => 40 * 60, 'people' => 2], $info['facts']);
        $this->assertSame(['planned' => 4, 'unplanned' => 0, 'support' => 0], $info['origins']);
        $this->assertSame([40, 0, 0], array_map(fn (array $part): int|float => $part['registered'] / 60, $info['hours']));

        $highlights = $info['highlights'];
        $this->assertSame(['value' => 30, 'names' => ['Ana']], $highlights['xp']);
        $this->assertSame(['value' => 2, 'names' => ['Ana']], $highlights['deliveries']);
        $this->assertNull($highlights['precision']);
        $this->assertSame([['Ana'], 1], [$highlights['clean'], $highlights['withCards']]);

        $trend = $info['trend'];
        $this->assertSame('Sprint 19', $trend['label']);
        $this->assertSame(['now' => 2, 'then' => 11, 'change' => '▼ −82%'], $trend['done']);
        $this->assertSame(['now' => 4, 'then' => 12, 'change' => '▼ −67%'], $trend['total']);
        $this->assertSame(['now' => 50.0, 'then' => 91.7, 'change' => '▼ −41,7 p.p.'], $trend['productivity']);
    }

    /**
     * @param  array<string, array<string, mixed>>  $results
     * @return list<?float>
     */
    private function values(array $results): array
    {
        return array_map(fn (string $key): ?float => $results[$key]['value'], ['productivity', 'rework', 'backlog']);
    }

    /**
     * @param  array<string, array<string, mixed>>  $results
     * @return list<string>
     */
    private function levels(array $results): array
    {
        return array_map(fn (string $key): string => $results[$key]['level'], ['productivity', 'rework', 'backlog']);
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     * @return list<array{0: string, 1: ?float, 2: int|float, 3: int|float}>
     */
    private function parts(array $parts): array
    {
        return array_map(fn (array $part): array => [$part['kind'], $part['value'], $part['part'] / 60, $part['whole'] / 60], $parts);
    }

    /**
     * Os cards do teste de navegador: horas apontadas por sprint 17 = 120h; 18 = 125h; 19 = 130h + 8h de suporte;
     * 20 = 40h; um cancelado na 20. Ana e Bruno no Full stack, 80h cada; reserva do Bombeiro de 16h por sprint.
     */
    private function data(): MetricsData
    {
        $rows = [];
        $cards = function (int $sprint, int $count, int $done = 0, int $rework = 0, int $real = 0, string $planned = 'Planejada', string $type = 'Melhoria') use (&$rows): void {
            for ($index = 0; $index < $count; $index++) {
                $rows[] = ['Sprint' => $sprint, 'Responsavel' => 'Ana', 'Status' => $index < $done ? 'Implementado' : 'Em progresso', 'Planejada' => $planned,
                    'Tipo' => $type, 'Retrabalho' => $index < $rework ? 1 : 0, 'EstH' => $real, 'RealH' => $real];
            }
        };
        $cards(17, 2, done: 2, real: 60);
        $cards(17, 3, done: 1, planned: 'Não Planejado');
        $cards(18, 5, done: 4, rework: 1, real: 25);
        $cards(19, 10, done: 9, rework: 1, real: 13);
        $cards(19, 2, done: 2, real: 4, planned: 'Não Planejado', type: 'Suporte');
        $cards(20, 4, done: 2, real: 10);
        $rows[] = ['Sprint' => 20, 'Responsavel' => 'Ana', 'Status' => 'CANCELADO', 'Planejada' => 'Planejada', 'Tipo' => 'Melhoria', 'Retrabalho' => 1, 'EstH' => 0, 'RealH' => 0];

        return new MetricsData(
            rows: $rows,
            sprintDates: [17 => ['start' => '2026-09-29', 'end' => '2026-10-10'], 18 => ['start' => '2026-10-13', 'end' => '2026-10-24'], 19 => ['start' => '2026-10-27', 'end' => '2026-11-07'],
                20 => ['start' => '2026-11-10', 'end' => '2026-11-21'], 21 => ['start' => '2026-11-24', 'end' => '2026-12-05']],
            settings: ['capacityHours' => 80, 'team' => [['id' => '1', 'name' => 'Ana', 'group' => 'fullstack'], ['id' => '2', 'name' => 'Bruno', 'group' => 'fullstack']]],
            supportReserves: array_fill_keys(['17', '18', '19', '20', '21'], 16 * 60),
            today: '2026-11-17',
        );
    }
}
