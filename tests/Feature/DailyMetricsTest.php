<?php

namespace Tests\Feature;

use App\Metrics\Daily;
use App\Metrics\MetricsData;
use Tests\TestCase;

class DailyMetricsTest extends TestCase
{
    public function test_xp_counts_deliveries_and_distinguishes_early_on_target_and_over_estimate(): void
    {
        $rows = [
            ['Status' => 'Implementado', 'EstH' => 10, 'RealH' => 8],
            ['Status' => 'Implementado', 'EstH' => 10, 'RealH' => 9],
            ['Status' => 'Implementado', 'EstH' => 10, 'RealH' => 11],
            ['Status' => 'Implementado', 'EstH' => null, 'RealH' => 1],
            ['Status' => 'Aguardando Deploy', 'EstH' => 10, 'RealH' => 8],
            ['Status' => 'Cancelado', 'EstH' => 10, 'RealH' => 8],
        ];

        $this->assertSame(['delivered' => 4, 'onTarget' => 2, 'early' => 1, 'xp' => 53, 'precision' => 50], Daily::score($rows));
        $this->assertSame('warn', Daily::cardHours(['Status' => 'Em progresso', 'EstH' => 10, 'RealH' => 8])['level']);
        $this->assertSame('over', Daily::cardHours(['Status' => 'Em progresso', 'EstH' => 10, 'RealH' => 11])['level']);
    }

    public function test_remaining_prefers_revised_hours_and_does_not_count_cards_ready_for_delivery(): void
    {
        $this->assertSame(120, Daily::remainingOf(['Status' => 'Em progresso', 'EstH' => 10, 'RealH' => 9, 'RemainingH' => 2]));
        $this->assertSame(600, Daily::remainingOf(['Status' => 'Backlog', 'EstH' => 10, 'RealH' => 9]));
        $this->assertSame(60, Daily::remainingOf(['Status' => 'Em progresso', 'EstH' => 10, 'RealH' => 9]));
        foreach (['QA - Aprovado', 'Aguardando Deploy', 'Implementado', 'Cancelado'] as $status) {
            $this->assertSame(0, Daily::remainingOf(['Status' => $status, 'EstH' => 10, 'RemainingH' => 2]));
        }
    }

    public function test_trend_counts_today_and_preserves_risk_at_zero_capacity(): void
    {
        $dates = ['start' => '2026-11-16', 'end' => '2026-11-20'];
        $result = Daily::trend(540, 1000, $dates, '2026-11-18');

        $this->assertSame(3, $result['left']);
        $this->assertSame(600, (int) $result['available']);
        $this->assertSame('yellow', $result['level']);
        $this->assertSame('red', Daily::trend(1, 0, $dates, '2026-11-23')['level']);
        $this->assertSame('green', Daily::trend(0, 0, null, '2026-11-23')['level']);
    }

    public function test_sprint_kanban_deduplicates_and_excludes_removed_and_historical_rows(): void
    {
        $daily = new Daily(new MetricsData([
            ['Id' => '1', 'Sprint' => 20, 'Status' => 'Implementado'],
            ['Id' => '1', 'Sprint' => 20, 'Status' => 'Em progresso'],
            ['Id' => '2', 'Sprint' => 20, 'Status' => 'Cancelado'],
            ['Id' => '3', 'Sprint' => 20, 'Status' => 'Implementado', 'AsOf' => '2026-11-17'],
            ['Id' => '4', 'Sprint' => 21, 'Status' => 'Implementado'],
        ]));

        $this->assertSame(['1'], array_column($daily->sprintCards(20), 'Id'));
        $this->assertSame('blocked', Daily::column('Pendente Samax'));
        $this->assertSame('qa', Daily::column('QA - Aprovado'));
        $this->assertSame('progress', Daily::column('Aguardando Deploy'));
    }

    public function test_person_hours_distinguish_pending_overrun_and_capacity_balance(): void
    {
        $hours = Daily::personHours(['capacity' => 600, 'totalEstimate' => 480, 'registered' => 540, 'rows' => []], null, '2026-11-18');

        $this->assertSame(0, $hours['pending']);
        $this->assertSame(60, $hours['overrun']);
        $this->assertSame(120, $hours['balance']);
        $this->assertSame(['planned' => 80, 'executed' => 113, 'pending' => 0, 'balance' => 20], $hours['percent']);
    }
}
