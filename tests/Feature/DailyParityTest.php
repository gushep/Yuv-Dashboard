<?php

namespace Tests\Feature;

use App\Metrics\Daily;
use App\Metrics\MetricsData;
use Tests\TestCase;

/**
 * Paridade com tests/daily.tests.html: os mesmos cards da sprint 20 (Ana e Bruno no Full stack, 80h cada, reserva do
 * Bombeiro de 40h, hoje 12/11/2026) e os mesmos valores que a tela Daily mostra.
 */
class DailyParityTest extends TestCase
{
    public function test_monday_statuses_map_to_the_five_columns(): void
    {
        $statuses = ['Backlog', 'Pronto para começar', 'Pendente', 'Refinar', 'Falta Escopo', 'Escopo Tecnico', 'Próx. Sprint', '', 'Parado', 'BLOQUEADO', 'Limitação Externa', 'Pendente Samax',
            'Em progresso', 'CR - Aguardando', 'QA - Testes', 'QA - Reprovado', 'QA - Aprovado', 'Aguardando Deploy', 'Implementado', 'Despriorizado', 'Descontinuado', 'CANCELADO'];

        $this->assertSame('pending,pending,pending,pending,pending,pending,pending,pending,blocked,blocked,blocked,blocked,progress,progress,qa,qa,qa,progress,done,,,',
            implode(',', array_map(fn (string $status): string => (string) Daily::column($status), $statuses)));
    }

    public function test_card_hours_badges_and_xp(): void
    {
        $hours = fn (int|float $estimate, int|float $real, string $status): array => Daily::cardHours(['EstH' => $estimate, 'RealH' => $real, 'Status' => $status]);

        $this->assertSame('', $hours(10, 7, 'Em progresso')['badge']);
        $this->assertSame('⚠️ perto do limite', $hours(10, 8, 'Em progresso')['badge']);
        $this->assertSame('🔥 estourou +2h', $hours(4, 6, 'Em progresso')['badge']);
        $this->assertSame(['⚡ adiantado:18', '🎯 no alvo:15', '🐢 +25% do estimado:10'],
            array_map(fn (array $item): string => "{$item['badge']}:{$item['points']}", [$hours(4, 3, 'Implementado'), $hours(4, 4, 'Implementado'), $hours(4, 5, 'Implementado')]));
    }

    public function test_remaining_hours_follow_the_control_tower_rule(): void
    {
        $this->assertSame(180, Daily::remainingOf(['Status' => 'QA - Reprovado', 'EstH' => 8, 'RealH' => 5]));
        $this->assertSame(720, Daily::remainingOf(['Status' => 'Pronto para começar', 'EstH' => 12, 'RealH' => 0]));
        $this->assertSame(300, Daily::remainingOf(['Status' => 'Em progresso', 'EstH' => 8, 'RealH' => 3]));
        $this->assertSame(0, Daily::remainingOf(['Status' => 'Em progresso', 'EstH' => 4, 'RealH' => 6]));
        $this->assertSame(0, Daily::remainingOf(['Status' => 'Implementado', 'EstH' => 4, 'RealH' => 1]));
        $this->assertSame(120, Daily::remainingOf(['Status' => 'Em progresso', 'EstH' => 8, 'RealH' => 3, 'RemainingH' => 2]));
        $this->assertSame(0, Daily::remainingOf(['Status' => 'Aguardando Deploy', 'EstH' => 8, 'RealH' => 3]));
        $this->assertSame(0, Daily::remainingOf(['Status' => 'QA - Aprovado', 'EstH' => 8, 'RealH' => 3]));
        $this->assertSame(360, Daily::remainingOf(['Status' => 'Refinar', 'EstH' => 6, 'RealH' => 0]));
    }

    public function test_trend_bands_against_the_remaining_capacity(): void
    {
        $week = ['start' => '2026-11-23', 'end' => '2026-11-27'];

        $this->assertSame(['green', 'yellow', 'yellow', 'red'], array_map(fn (int $hours): string => Daily::trend($hours * 60, 40 * 60, $week, '2026-11-23')['level'], [35, 36, 40, 41]));
        $this->assertSame('red', Daily::trend(30 * 60, 40 * 60, $week, '2026-11-25')['level']);
    }

    public function test_sprint_trend_compares_fullstack_with_capacity_minus_the_firefighter_reserve(): void
    {
        $daily = new Daily($this->data([['id' => '3', 'name' => 'Carla', 'group' => 'projects']]));
        $devs = [['name' => 'Ana', 'team' => 'fullstack', 'capacity' => 80 * 60], ['name' => 'Bruno', 'team' => 'fullstack', 'capacity' => 80 * 60], ['name' => 'Carla', 'team' => 'projects', 'capacity' => 40 * 60]];
        $card = fn (string $owner, int $estimate): array => ['Responsavel' => $owner, 'EstH' => $estimate, 'RealH' => 0, 'Status' => 'Backlog'];
        $week = ['start' => '2026-11-23', 'end' => '2026-11-27'];

        $trend = $daily->sprintTrend([$card('Ana', 60), $card('Bruno', 40), $card('Carla', 10)], $devs, 20, $week, '2026-11-23');

        $this->assertSame('green', $trend['level']);
        $this->assertSame(100 * 60, $trend['remaining']);
        $this->assertEquals(120 * 60, $trend['available']);
        $this->assertSame('Full stack: faltam 100h para 120h de capacidade (160h − Bombeiro 40h) nos 5 dia(s) útil(eis) restantes · 83%. Projetos: faltam 10h para 40h.', $trend['detail']);
        $this->assertSame('yellow', $daily->sprintTrend([$card('Ana', 70), $card('', 40)], $devs, 20, $week, '2026-11-23')['level']);
    }

    public function test_macro_indicator_of_the_sprint(): void
    {
        $sprint = (new Daily($this->data()))->sprint(20);

        $this->assertSame(['pending' => 1, 'blocked' => 2, 'progress' => 3, 'qa' => 1, 'done' => 1], $sprint['columns']);
        $this->assertSame(8, $sprint['cards']);
        $this->assertSame(['capacity' => 160 * 60, 'planned' => 56 * 60, 'registered' => 26 * 60], $sprint['hours']);
        $this->assertSame('🟢 No prazo', $sprint['trend']['label']);
        $this->assertSame(['delivered' => 1, 'onTarget' => 1, 'early' => 0, 'xp' => 15, 'precision' => 100], $sprint['score']);
    }

    public function test_person_panel_hours_trend_xp_and_kanban(): void
    {
        [$ana, $bruno] = (new Daily($this->data()))->sprint(20)['people'];

        $this->assertSame('Ana', $ana['name']);
        $this->assertSame([80 * 60, 40 * 60, 17 * 60], [$ana['capacity'], $ana['planned'], $ana['executed']]);
        $this->assertSame([23 * 60, 40 * 60], [$ana['hours']['pending'], $ana['hours']['balance']]);
        $this->assertSame(['planned' => 50, 'executed' => 43, 'pending' => 57, 'balance' => 50], $ana['hours']['percent']);
        $this->assertSame('🟢 No prazo', $ana['hours']['trend']['label']);
        $this->assertSame(0, $ana['burning']);
        $this->assertSame(15, $ana['score']['xp']);
        $this->assertTrue($ana['achievement']);
        $this->assertSame([1, 1, 2, 1, 1], array_values($ana['columns']));
        $this->assertSame([0, 1, 1, 0, 0], array_values($bruno['columns']));
    }

    public function test_planned_above_capacity_gives_negative_balance_and_executed_above_planned_zero_pending(): void
    {
        $over = Daily::personHours(['rows' => [], 'capacity' => 10 * 60, 'totalEstimate' => 12 * 60, 'registered' => 13 * 60], ['start' => '2026-11-23', 'end' => '2026-11-27'], '2026-11-26');

        $this->assertSame([0, 60, -2 * 60], [$over['pending'], $over['overrun'], $over['balance']]);
        $this->assertSame(-20, $over['percent']['balance']);
        $this->assertSame(108, $over['percent']['executed']);
    }

    /**
     * Os cards do teste de navegador (sprint 20 de 10 a 21/11), com o card cancelado que fica fora.
     *
     * @param  list<array{id: string, name: string, group: string}>  $extraTeam
     */
    private function data(array $extraTeam = []): MetricsData
    {
        $row = fn (string $id, string $name, string $owner, string $status, int|float $estimate, int|float $real, string $type = 'Melhoria'): array => [
            'Id' => $id, 'Nome' => $name, 'Responsavel' => $owner, 'Status' => $status, 'EstH' => $estimate, 'RealH' => $real, 'Tipo' => $type, 'Planejada' => 'Planejada', 'Sprint' => 20, 'Url' => '',
        ];

        return new MetricsData(
            rows: [
                $row('1', 'Relatório de jornada', 'Ana', 'Parado', 10, 4), $row('2', 'Login FaceID', 'Ana', 'Em progresso', 8, 3) + ['Prioridade' => 'Baixa'],
                $row('3', 'Ajuste de mapa', 'Ana', 'QA - Testes', 6, 6) + ['Prioridade' => 'Crítica'], $row('4', 'Exportar CSV', 'Ana', 'Implementado', 4, 4),
                $row('5', 'Cerca eletrônica', 'Ana', 'Pronto para começar', 12, 0), $row('6', 'Card cancelado', 'Ana', 'CANCELADO', 5, 0),
                $row('7', 'Painel de frota', 'Bruno', 'Pendente Samax', 9, 2), $row('8', 'Gamificação', 'Bruno', 'Aguardando Deploy', 7, 7),
                $row('9', 'Revisão do mapa', 'Ana', 'CR - Aguardando', 0, 0) + ['Prioridade' => 'Alta'],
            ],
            sprintDates: [20 => ['start' => '2026-11-10', 'end' => '2026-11-21']],
            settings: ['capacityHours' => 80, 'team' => [['id' => '1', 'name' => 'Ana', 'group' => 'fullstack'], ['id' => '2', 'name' => 'Bruno', 'group' => 'fullstack'], ...$extraTeam]],
            supportReserves: ['20' => 2400],
            today: '2026-11-12',
        );
    }
}
