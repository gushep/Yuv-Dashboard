<?php

namespace Tests\Feature;

use App\Metrics\MetricsData;
use App\Metrics\Okrs;
use Tests\TestCase;

class OkrsMetricsTest extends TestCase
{
    public function test_period_uses_weighted_totals_and_splits_capacity_and_support_per_person(): void
    {
        $result = (new Okrs($this->data()))->period(20, 19);

        $this->assertSame([19, 20], $result['sprints']);
        $this->assertTrue($result['partial']);
        $this->assertSame(66.7, $result['results']['productivity']['value']);
        $this->assertSame(33.3, $result['results']['rework']['value']);
        $this->assertSame(35.0, $result['results']['backlog']['value']);
        $this->assertSame(4800, $result['results']['backlog']['whole']);
        $this->assertSame([960, 240, 480], array_column($result['results']['backlog']['parts'], 'part'));
        $this->assertSame([1920, 480, 2400], array_column($result['results']['backlog']['parts'], 'whole'));
        $ana = $result['people'][0];
        $this->assertSame('Ana', $ana['name']);
        $this->assertSame(480, $ana['supportCapacity']);
        $this->assertSame(240, $ana['supportRegistered']);
        $this->assertSame(50.0, $ana['results']['backlog']['value']);
        $this->assertSame([960, 240], array_column($ana['results']['backlog']['parts'], 'part'));
        $this->assertSame(33, $ana['score']['xp']);
        $this->assertSame(2400, $result['people'][1]['capacity']);
        $this->assertSame(0, $result['people'][1]['supportCapacity']);
    }

    public function test_future_sprints_do_not_enter_history_and_months_use_sprint_start(): void
    {
        $okrs = new Okrs($this->data());

        $this->assertSame([19, 20], $okrs->startedSprints());
        $this->assertSame([], $okrs->between(21, 22));
        $result = $okrs->period(19, 20);
        $this->assertSame(['2026-11', '2026-10'], array_column($result['months'], 'month'));
        $this->assertSame([19, 20], array_column($result['growth'], 'sprint'));
        $this->assertSame([1, 2], array_column($result['growth'], 'total'));
    }

    public function test_empty_period_has_no_denominator_or_artificial_zero_percent(): void
    {
        $result = (new Okrs(new MetricsData([])))->period(1, 2);

        $this->assertSame([], $result['people']);
        $this->assertNull($result['results']['productivity']['value']);
        $this->assertSame('none', $result['results']['backlog']['level']);
    }

    private function data(): MetricsData
    {
        $card = fn (string $id, int $sprint, array $values): array => ['Id' => $id, 'Nome' => 'Card '.$id, 'Sprint' => $sprint, 'Responsavel' => 'Ana', 'Status' => 'Implementado', 'Planejada' => 'Planejada', 'Tipo' => 'Melhoria', 'EstH' => 10, 'RealH' => 8, ...$values];

        return new MetricsData(
            rows: [
                $card('1', 19, []),
                $card('2', 20, ['Tipo' => 'Suporte', 'EstH' => 4, 'RealH' => 4, 'Retrabalho' => 1]),
                $card('3', 20, ['Responsavel' => 'Bia', 'Status' => 'Em progresso']),
                $card('4', 21, []),
                $card('5', 20, ['Status' => 'Cancelado']),
            ],
            sprintDates: [19 => ['start' => '2026-10-26', 'end' => '2026-11-06'], 20 => ['start' => '2026-11-16', 'end' => '2026-11-27'], 21 => ['start' => '2026-11-30', 'end' => '2026-12-11']],
            settings: ['capacityHours' => 20, 'excludedPeople' => [], 'team' => [['name' => 'Ana', 'group' => 'fullstack'], ['name' => 'Bia', 'group' => 'projects']]],
            supportReserves: ['19' => 240, '20' => 240], today: '2026-11-18',
        );
    }
}
