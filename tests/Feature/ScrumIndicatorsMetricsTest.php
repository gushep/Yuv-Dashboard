<?php

namespace Tests\Feature;

use App\Metrics\MetricsData;
use App\Metrics\ScrumIndicators;
use Tests\TestCase;

/**
 * Paridade com tests/scrum-indicators.tests.html: mesmos cards, mesmas datas e mesmos valores esperados.
 */
class ScrumIndicatorsMetricsTest extends TestCase
{
    public function test_measure_matches_the_screen_for_the_before_and_after_periods(): void
    {
        $indicators = new ScrumIndicators($this->data());

        $before = $indicators->measure([1, 2, 3, 4]);
        $this->assertSame(7.0, (float) $before['donePerSprint']);
        $this->assertSame(70.0, $before['productivity']);
        $this->assertSame(66.7, round($before['plannedDelivered'], 1));
        $this->assertSame(20.0, round($before['deviation'], 1));
        $this->assertSame(60.0, $before['withEstimate']);
        $this->assertSame(60.0, $before['ready']);
        $this->assertSame(10.0, $before['rework']);
        $this->assertSame(0.0, (float) $before['supportPerSprint']);

        // Após: 19 de 20 entregues, sem o card cancelado; desvio = 136 ÷ 126 − 1.
        $after = $indicators->measure([5, 6, 7]);
        $this->assertSame(20.0, (float) $after['cardsPerSprint']);
        $this->assertSame(19.0, (float) $after['donePerSprint']);
        $this->assertSame(95.0, $after['productivity']);
        $this->assertSame(100.0, $after['plannedDelivered']);
        $this->assertSame(7.9, round($after['deviation'], 1));
        $this->assertSame(100.0, $after['ready']);
        $this->assertSame(5.0, (float) $after['supportPerSprint']);
        $this->assertSame(100.0, $after['supportWithHours']);
        $this->assertSame(5.0, $after['rework']);
        $this->assertSame(50.0, $after['client']);
        $this->assertSame(15.0, (float) $after['nonSupportPerSprint']);
    }

    public function test_change_badges_match_the_screen(): void
    {
        $this->assertSame('×2,7', ScrumIndicators::change('ratio', 7, 19, 'higher')['text']);
        $this->assertSame('+25 p.p.', ScrumIndicators::change('pp', 70, 95, 'higher')['text']);
        $this->assertSame('−50%', ScrumIndicators::change('relative', 10, 5, 'lower')['text']);
        $this->assertSame('3× menor', ScrumIndicators::change('deviation', 20, 7.94, 'lower')['text']);
        $this->assertSame('novo', ScrumIndicators::change('ratio', 0, 5, 'higher')['text']);
        $this->assertFalse(ScrumIndicators::change('pp', 95, 70, 'higher')['good']);
        $this->assertSame(['text' => 'sem base', 'good' => null], ScrumIndicators::change('pp', null, 70, 'higher'));
        $this->assertSame(['text' => 'estável', 'good' => null], ScrumIndicators::change('pp', 70, 70.01, 'higher'));
    }

    public function test_default_periods_leave_the_running_sprint_out(): void
    {
        $this->assertSame(['beforeFrom' => 1, 'beforeTo' => 4, 'afterFrom' => 5, 'afterTo' => 7], (new ScrumIndicators($this->data()))->defaults());
    }

    public function test_infographic_numbers_and_texts_match_the_screen(): void
    {
        $result = (new ScrumIndicators($this->data()))->compare([1, 2, 3, 4], [5, 6, 7]);

        $this->assertSame('Sprints 1 a 4 (4)', $result['before']['label']);
        $this->assertSame('Sprints 5 a 7 (3)', $result['after']['label']);
        $this->assertSame('+171%', $result['headline']['growth']);
        $this->assertStringStartsWith('O time entrega 2,7× mais por sprint, com mais controle e menos retrabalho', $result['headline']['title']);
        $hero = array_map(fn (array $kpi): string => "{$kpi['after']}|{$kpi['before']}|{$kpi['tag']['text']}", $result['hero']);
        $this->assertSame(['95%|70%|meta 90% ✓', '100%|67%|+33 p.p.', '5%|10%|−50%', '+7,9%|+20%|3× menor'], $hero);
        $fronts = array_map(fn (array $front): string => "{$front['title']}:{$front['after']}:{$front['badge']}", $result['fronts']);
        $this->assertCount(8, $fronts);
        $this->assertSame('Volume de entrega:19:×2,7', $fronts[0]);
        $this->assertSame('Sustentação sob controle:5:novo', $fronts[4]);
        $this->assertSame('Qualidade:5%:−50%', $fronts[6]);
        $this->assertStringContainsString('a média vai de 10 para 15 cards por sprint (+50%)', $result['note']);
        $this->assertCount(7, $result['chart']);
        $this->assertCount(3, array_filter($result['chart'], fn (array $point): bool => $point['group'] === 'after'));
        $this->assertFalse($result['overlap']);
        $this->assertFalse($result['partial']);
    }

    public function test_a_worse_period_shows_red_badges_and_less_volume(): void
    {
        $result = (new ScrumIndicators($this->data()))->compare([5, 6, 7], [1]);

        $this->assertFalse($result['hero'][0]['tag']['good']);
        $this->assertStringStartsWith('O time entrega 63% menos por sprint', $result['headline']['title']);
        $this->assertNotEmpty(array_filter($result['fronts'], fn (array $front): bool => $front['good'] === false));
    }

    public function test_overlapping_periods_and_the_running_sprint_are_flagged(): void
    {
        $indicators = new ScrumIndicators($this->data());

        $this->assertTrue($indicators->compare($indicators->between(1, 5), $indicators->between(5, 7))['overlap']);
        $this->assertTrue($indicators->compare($indicators->between(1, 4), $indicators->between(5, 8))['partial']);
    }

    /**
     * Antes (sprints 1 a 4): 10 cards por sprint; 6 planejados (4 entregues, 10h × 12h) e 4 não planejados sem
     * estimativa (3 entregues); 1 com retrabalho. Após (5 a 7): 20 por sprint; 10 planejados entregues (10h × 11h),
     * 5 não planejados (4 entregues, 4h × 4h) e 5 de suporte (2h × 2h); 1 com retrabalho; metade com cliente.
     * Sprint 8 em andamento e um card cancelado (fora da conta).
     */
    private function data(): MetricsData
    {
        $rows = [];
        $card = function (int $sprint, array $options = []) use (&$rows): void {
            $o = $options + ['done' => false, 'est' => 0, 'real' => 0, 'Planejada' => 'Planejada', 'Tipo' => 'Melhoria', 'rework' => false, 'Cliente' => ''];
            $rows[] = ['Sprint' => $sprint, 'Nome' => 'Card '.count($rows), 'Status' => $o['done'] ? 'Implementado' : 'Em progresso', 'EstH' => $o['est'], 'RealH' => $o['real'],
                'Planejada' => $o['Planejada'], 'Tipo' => $o['Tipo'], 'Retrabalho' => $o['rework'] ? 1 : 0, 'Prioridade' => 'Alta', 'Responsavel' => 'Ana', 'Cliente' => $o['Cliente']];
        };
        foreach ([1, 2, 3, 4] as $sprint) {
            for ($i = 0; $i < 6; $i++) {
                $card($sprint, ['done' => $i < 4, 'est' => 10, 'real' => $i < 4 ? 12 : 3, 'rework' => $i === 0]);
            }
            for ($i = 0; $i < 4; $i++) {
                $card($sprint, ['done' => $i < 3, 'real' => 5, 'Planejada' => 'Não Planejado']);
            }
        }
        foreach ([5, 6, 7] as $sprint) {
            for ($i = 0; $i < 10; $i++) {
                $card($sprint, ['done' => true, 'est' => 10, 'real' => 11, 'rework' => $i === 0, 'Cliente' => 'Yuv']);
            }
            for ($i = 0; $i < 5; $i++) {
                $card($sprint, ['done' => $i < 4, 'est' => 4, 'real' => $i < 4 ? 4 : 1, 'Planejada' => 'Não Planejado']);
            }
            for ($i = 0; $i < 5; $i++) {
                $card($sprint, ['done' => true, 'est' => 2, 'real' => 2, 'Tipo' => 'Suporte', 'Planejada' => 'Não Planejado']);
            }
        }
        for ($i = 0; $i < 3; $i++) {
            $card(8, ['est' => 5]);
        }
        $rows[] = ['Sprint' => 5, 'Nome' => 'Cancelado', 'Status' => 'CANCELADO', 'EstH' => 99, 'RealH' => 99, 'Planejada' => 'Planejada', 'Tipo' => 'Melhoria', 'Retrabalho' => 1, 'Prioridade' => 'Alta', 'Responsavel' => 'Ana'];
        $dates = [
            1 => ['start' => '2026-08-03', 'end' => '2026-08-14'], 2 => ['start' => '2026-08-17', 'end' => '2026-08-28'],
            3 => ['start' => '2026-08-31', 'end' => '2026-09-11'], 4 => ['start' => '2026-09-14', 'end' => '2026-09-25'],
            5 => ['start' => '2026-09-28', 'end' => '2026-10-09'], 6 => ['start' => '2026-10-12', 'end' => '2026-10-23'],
            7 => ['start' => '2026-10-26', 'end' => '2026-11-06'], 8 => ['start' => '2026-11-09', 'end' => '2026-11-20'],
        ];

        return new MetricsData(rows: $rows, sprintDates: $dates, today: '2026-11-17');
    }
}
