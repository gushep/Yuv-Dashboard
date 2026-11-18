<?php

namespace Tests\Feature;

use App\Metrics\Forecast;
use App\Metrics\MetricsData;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Paridade com tests/forecast.tests.html: mesmos dados e mesmos valores esperados da Previsibilidade.
 */
class ForecastMetricsTest extends TestCase
{
    private const DATES = ['start' => '2026-09-28', 'end' => '2026-10-09'];

    public function test_durations_are_normalized_to_minutes(): void
    {
        $this->assertSame(90, Forecast::duration('1h30'));
        $this->assertSame(90, Forecast::duration('1,5'));
        $this->assertSame(90, Forecast::duration('1:30'));
        $this->assertSame(90, Forecast::duration('90m'));
        $this->assertSame('1h 30min', Forecast::format(90));
    }

    public function test_missing_and_invalid_durations_are_null_and_zero_stays_zero(): void
    {
        $this->assertNull(Forecast::duration(null));
        $this->assertNull(Forecast::duration(' '));
        $this->assertNull(Forecast::duration('1h90'));
        $this->assertNull(Forecast::duration(-1));
        $this->assertSame(0, Forecast::duration(0));
    }

    public function test_remaining_capacity_excludes_the_whole_reference_day(): void
    {
        $this->assertSame(['remaining' => 1440, 'days' => 3, 'totalDays' => 10], Forecast::capacity(4800, self::DATES, '2026-10-06'));
        $this->assertSame(4800, Forecast::capacity(4800, self::DATES, '2026-09-27')['remaining']);
        $this->assertSame(0, Forecast::capacity(4800, self::DATES, '2026-10-10')['remaining']);
        $this->assertSame([], Forecast::workingDays('2026-10-03', '2026-10-04'));
        $this->assertNull(Forecast::capacity(4800, null, '2026-10-06')['remaining']);
        $this->assertNull(Forecast::workingDays('2026-02-30', '2026-03-01'));
        $this->assertSame(1067, Forecast::capacity(4800, self::DATES, '2026-10-06', ['2026-10-08'])['remaining']);
    }

    public function test_model_sums_open_tasks_and_flags_the_ones_to_review(): void
    {
        $result = Forecast::model($this->fixtures(), 17, self::DATES, [], '2026-10-06');
        $ana = $this->developer($result, 'Ana');
        $bia = $this->developer($result, 'Bia');
        $task = fn (string $id): array => collect($ana['tasks'])->firstWhere('row.Id', $id);

        $this->assertCount(9, $ana['tasks']);
        $this->assertEmpty(array_intersect(['1', '2'], array_column(array_column($ana['tasks'], 'row'), 'Id')));
        $this->assertSame([810, 6, 120, 1], [$ana['known'], $ana['missing'], $bia['known'], $bia['missing']]);
        foreach (['5', '6', '8', '9'] as $id) {
            $this->assertNull($task($id)['remainingEffort'], "Tarefa {$id}");
        }
        $this->assertCount(1, $result['unassigned']);
        $this->assertSame('unknown', $ana['status']);
        $this->assertSame('Revisar estimativas', Forecast::balance($ana));
    }

    #[TestWith([49, 'low'])]
    #[TestWith([50, 'within'])]
    #[TestWith([89, 'within'])]
    #[TestWith([90, 'limit'])]
    #[TestWith([100, 'limit'])]
    #[TestWith([101, 'over'])]
    public function test_load_bands(int $known, string $status): void
    {
        $this->assertSame($status, Forecast::classify($known, 100, 0, 1));
    }

    public function test_zero_capacity_incomplete_forecast_and_balance_texts(): void
    {
        $this->assertSame('over', Forecast::classify(1, 0, 0, 1));
        $this->assertSame('unknown', Forecast::classify(0, 0, 1, 1));
        $this->assertSame('empty', Forecast::classify(0, 0, 0, 0));
        $this->assertSame('unknown', Forecast::classify(0, 0, 0, 1));
        $this->assertSame('pelo menos 1h a mais', Forecast::balance(['status' => 'over', 'known' => 180, 'available' => 120, 'missing' => 1]));
        $this->assertSame('over', Forecast::classify(180, 120, 1, 2));
        $this->assertSame('1h de folga', Forecast::balance(['status' => 'within', 'known' => 60, 'available' => 120, 'missing' => 0]));
        $this->assertSame('Sem folga', Forecast::balance(['status' => 'limit', 'known' => 120, 'available' => 120, 'missing' => 0]));
    }

    public function test_single_task_rules(): void
    {
        $first = fn (array $row): array => Forecast::model([$row], 17, self::DATES, [], '2026-10-06')['developers'][0];

        $this->assertSame(1, $first($this->row(null, 'Ana', 'Em progresso', 10, null))['missing']);
        $this->assertSame(120, $first($this->row('14', 'Ana', 'Pronto pra começar', 2, null))['known']);
        $this->assertSame(120, $first($this->row('15', 'Ana, Ana', 'Backlog', 2, 0))['known']);
        $this->assertSame(0, $first($this->row('16', 'Ana', 'Em progresso', 1, 2, ['RemainingH' => 0]))['missing']);
        $this->assertSame(360, $first($this->row('17', 'Ana', 'Em progresso', 10, 4))['known']);
    }

    public function test_team_summary_discounts_the_future_firefighter_reserve_only_after_teams_are_set(): void
    {
        $teams = Forecast::teamForecast([['team' => 'fullstack', 'known' => 600, 'available' => 1440], ['team' => 'projects', 'known' => 60, 'available' => 480]], 4800, self::DATES, '2026-10-06');
        $this->assertSame([1920, 1440, 60], [$teams['total']['available'], $teams['fullstack']['available'], $teams['projects']['known']]);

        $configured = Forecast::teamForecast([['team' => 'fullstack', 'known' => 600, 'available' => 1440]], 4800, self::DATES, '2026-10-06', ['team' => [['id' => '1', 'name' => 'Ana', 'group' => 'fullstack']]]);
        $this->assertSame([1440, 0, 1440], [$configured['fullstack']['reserve'], $configured['fullstack']['available'], $configured['total']['available']]);
    }

    public function test_build_uses_the_saved_capacity_and_sorts_the_excess_first(): void
    {
        $data = new MetricsData(
            rows: [
                $this->row('20', 'Ana', 'Backlog', 30, null, ['Impedimento' => 'Aguardando acesso']),
                $this->row('21', 'Bia', 'Backlog', 8, null),
                $this->row('22', 'Cris', 'Backlog', 2, null, ['Sprint' => 18]),
            ],
            sprintDates: [17 => self::DATES, 18 => self::DATES],
            capacityOverrides: ['17|Bia' => 2400],
            today: '2026-10-06',
        );

        $forecast = Forecast::build($data, 17);

        $this->assertSame(['Ana', 'Bia'], array_column($forecast['developers'], 'name'));
        $this->assertSame('6h a mais', Forecast::balance($forecast['developers'][0]));
        $this->assertSame('Aguardando acesso', Forecast::blocker($forecast['developers'][0]['tasks'][0]['row']));
        $this->assertSame('12h', Forecast::format($forecast['developers'][1]['available']));
        $this->assertSame(0, $forecast['teams']['fullstack']['reserve']);
        $this->assertSame(['Cris'], array_column(Forecast::build($data, 18)['developers'], 'name'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixtures(): array
    {
        return [
            $this->row('1', 'Ana', 'Implementado'), $this->row('2', 'Ana', 'Cancelado'), $this->row('3', 'Ana', 'Backlog', 4, null),
            $this->row('4', 'Ana', 'Em progresso', 10, 2), $this->row('4', 'Ana', 'Em progresso', 10, 2), $this->row('5', 'Ana', 'Em progresso', 5, 5),
            $this->row('6', 'Ana', 'Em progresso', 5, null), $this->row('7', 'Ana', 'Em progresso', null, 1), $this->row('8', 'Ana, Bia', 'Backlog', 10, 0),
            $this->row('9', 'Ana', 'Em progresso', 4, 1, ['Sprint' => 18]), $this->row('9', 'Ana', 'Em progresso', 4, 1), $this->row('10', '', 'Backlog', 1, 0),
            $this->row('11', 'Bia', 'Backlog', 2, null, ['Planejada' => 'Não Planejado', 'Tipo' => 'Suporte']),
            $this->row('12', 'Ana', 'Em progresso', 2, 3, ['RemainingH' => 1.5]), $this->row('13', 'Ana', 'Em progresso', 0, 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function row(?string $id, string $owner = 'Ana', string $status = 'Em progresso', int|float|null $estimate = 10, int|float|null $real = 2, array $extra = []): array
    {
        return ['Id' => $id, 'Nome' => "Tarefa {$id}", 'Responsavel' => $owner, 'Status' => $status, 'EstH' => $estimate, 'RealH' => $real, 'Sprint' => 17, 'Tipo' => 'Melhoria', 'Planejada' => 'Planejada', ...$extra];
    }

    /**
     * @param  array{developers: list<array<string, mixed>>}  $result
     * @return array<string, mixed>
     */
    private function developer(array $result, string $name): array
    {
        return collect($result['developers'])->firstWhere('name', $name);
    }
}
