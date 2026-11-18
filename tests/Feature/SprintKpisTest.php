<?php

namespace Tests\Feature;

use App\Metrics\MetricsData;
use App\Metrics\SprintKpis;
use Tests\TestCase;

/**
 * Paridade com as contas do Dashboard, Sprints, Épicos e Time (resources/views/dashboard.blade.php). Não há teste
 * de navegador dessas telas: os valores esperados foram calculados pelas regras do front para os cards abaixo.
 */
class SprintKpisTest extends TestCase
{
    public function test_metrics_of_a_sprint_follow_the_screen_rules(): void
    {
        $kpis = new SprintKpis($this->data());

        $m = $kpis->metricsOf($this->data()->rowsOf(1));

        $expected = ['tot' => 4, 'removed' => 1, 'done' => 2, 'waiting' => 1, 'inTest' => 1, 'inTestRejected' => 1, 'inTestApproved' => 0, 'open' => 1,
            'devFinished' => 3, 'devFinishedPct' => 75, 'support' => 1, 'supportDone' => 1, 'planned' => 2, 'plannedDone' => 1, 'unplanned' => 1,
            'unplannedDone' => 0, 'unplannedCrit' => 1, 'unplannedBug' => 1, 'unplannedPct' => 25, 'unplan' => 2, 'interruptPct' => 50, 'rework' => 1,
            'reworkPct' => 25, 'bugs' => 1, 'valor' => 1, 'drag' => 0, 'arrastPct' => 0, 'withEst' => 3, 'aderencia' => 75, 'epicItems' => 1, 'epicPct' => 25,
            'epicDone' => 0, 'cleanScope' => 2, 'cleanPct' => 67, 'conclusao' => 50, 'unplanCrit' => 1, 'unplanBug' => 1];
        $this->assertSame($expected, array_map(fn (string $key): mixed => $m[$key], array_combine(array_keys($expected), array_keys($expected))));
        $this->assertSame([['id' => 'a', 'nome' => 'Login novo', 'sprint' => 1], ['id' => 'c', 'nome' => 'Ticket 1', 'sprint' => 1]], $m['lists']['done']);
        $this->assertSame(['b'], array_column($m['lists']['noEst'], 'id'));
    }

    public function test_dragged_cards_come_from_the_flag_an_earlier_id_or_a_copy_name(): void
    {
        $m = (new SprintKpis($this->data()))->metricsOf($this->data()->rowsOf(2));

        $this->assertSame(['b', 'z', 'y'], array_column($m['lists']['drag'], 'id'));
        $this->assertSame(100, $m['arrastPct']);
        $this->assertSame(67, $m['devFinishedPct']);
        $this->assertSame(2, $m['open']);
    }

    public function test_estimate_precision_labels(): void
    {
        $kpis = new SprintKpis($this->data());

        // Como no front, a precisão usa os cards informados sem tirar os retirados do escopo: (20 + 0 + 0 − 50) ÷ 4.
        $this->assertSame('superestimou 7.5%', $kpis->precStats($this->data()->rowsOf(1))['label']);
        $this->assertSame('subestimou 50%', $kpis->precStats($this->data()->rowsOf(10))['label']);
        $this->assertSame(['avg' => null, 'label' => 'sem dados'], $kpis->precStats([]));
    }

    public function test_dashboard_totals_series_and_compositions(): void
    {
        $dashboard = (new SprintKpis($this->data()))->dashboard();

        $this->assertSame([1, 2, 10], $dashboard['sprints']);
        $this->assertSame(8, $dashboard['total']['tot']);
        $this->assertSame(3, $dashboard['averagePerSprint']);
        $this->assertSame([['Melhoria', 4], ['Bug', 2], ['Suporte', 1], ['Épico', 1]], $dashboard['types']);
        $this->assertSame(['clean' => 6, 'rework' => 1, 'bug' => 1, 'both' => 0], $dashboard['quality']);
        $first = $dashboard['perSprint'][0];
        $this->assertSame([1, 50, 1, 1], [$first['sprint'], $first['conclusao'], $first['bugs'], $first['valor']]);
        $this->assertSame(['planned' => 16.0, 'unplanned' => 3.0, 'support' => 2.0, 'drag' => 0.0, 'total' => 21.0, 'missing' => 0, 'tot' => 4], $first['volumeHours']['RealH']);
        $this->assertSame(['planned' => 18.0, 'unplanned' => 0.0, 'support' => 2.0, 'drag' => 0.0, 'total' => 20.0, 'missing' => 1, 'tot' => 4], $first['volumeHours']['EstH']);
    }

    public function test_sprint_screen_compares_with_the_previous_sprint_and_counts_people_of_removed_cards(): void
    {
        $kpis = new SprintKpis($this->data());

        $second = $kpis->sprint(2);
        $this->assertSame(1, $second['previousSprint']);
        $this->assertSame(50, $second['previousMetrics']['conclusao']);
        $this->assertSame([['Implementado', 1], ['Em progresso', 1], ['QA - Testes', 1]], $second['status']);
        $this->assertSame([['Alta', 3]], $second['priorities']);
        $this->assertSame(['planned' => 2, 'unplanned' => 1], $second['planning']);
        $this->assertSame([['App', 1]], $second['epics']);
        $this->assertSame([['name' => 'Ana', 'total' => 2, 'done' => 0, 'pct' => 0], ['name' => 'Bruno', 'total' => 1, 'done' => 1, 'pct' => 100]], $second['devs']);

        // Como no front, a contagem por pessoa usa todos os cards da sprint (inclusive o cancelado de Carla) e ignora quem está fora das métricas.
        $this->assertSame(['Bruno' => 3, 'Ana' => 2, 'Carla' => 1], array_column($kpis->sprint(1)['devs'], 'total', 'name'));
        $this->assertNull($kpis->sprint(1)['previousMetrics']);
    }

    public function test_epics_with_hours_sprints_and_share_per_sprint(): void
    {
        $epics = (new SprintKpis($this->data()))->epics();

        $this->assertSame([['epic' => 'App', 'tasks' => 3, 'done' => 1, 'hours' => 18.0, 'sprints' => [1, 10, 2]]], $epics['epics']);
        $this->assertSame([20, 33, 100], array_map(fn (array $sprint): int => $sprint['shares']['App'], $epics['perSprint']));
        $this->assertSame(3, $epics['items']);
    }

    public function test_team_overview_and_one_person_with_capacity(): void
    {
        $kpis = new SprintKpis($this->data());

        $overview = $kpis->team();
        $this->assertSame(['Ana', 'Bruno', 'Carla'], array_column($overview['devs'], 'name'));
        $ana = $overview['devs'][0];
        $this->assertSame([5, 3, 60, 100, 15.0, 3, 1, 2, 'preciso'], [$ana['tot'], $ana['done'], $ana['conclusao'], $ana['aderencia'], $ana['allocH'], $ana['epicItems'], $ana['epicDone'], $ana['open'], $ana['precLabel']]);
        $this->assertSame([8.0, 1.0, 6.0], $overview['hoursPerSprint']['devs']['Ana']);

        // Capacidade: 50h salvas na sprint 1, herdadas nas seguintes.
        $person = $kpis->team('Ana');
        $this->assertSame([150.0, 10, 135.0, 56], [$person['capacityHours'], $person['occupancy'], $person['gap'], $person['pctOfTeam']]);
        $this->assertSame([50.0, 50.0, 50.0], array_column($person['perSprint'], 'capacityHours'));
        $oneSprint = $kpis->team('Ana', 1);
        $this->assertSame([2, 8.0, 50.0, 16, 40], [$oneSprint['stats']['tot'], $oneSprint['stats']['allocH'], $oneSprint['capacityHours'], $oneSprint['occupancy'], $oneSprint['pctOfTeam']]);
        $this->assertSame(80.0, $kpis->team('Bruno', 1)['capacityHours']);
    }

    private function data(): MetricsData
    {
        $card = fn (string $id, string $name, int $sprint, string $status, string $type, string $planned, float $est, float $real, string $people, string $priority, array $extra = []): array => [
            'Id' => $id, 'Nome' => $name, 'Sprint' => $sprint, 'Status' => $status, 'Tipo' => $type, 'Planejada' => $planned, 'EstH' => $est, 'RealH' => $real,
            'Responsavel' => $people, 'Prioridade' => $priority, 'Epico' => '', 'Retrabalho' => false, 'BugProducao' => false, 'ValorAgregado' => false, 'Arrastado' => false, ...$extra,
        ];

        return new MetricsData(
            rows: [
                $card('a', 'Login novo', 1, 'Implementado', 'Melhoria', 'Planejada', 10, 12, 'Ana, Bruno', 'Alta', ['Epico' => 'App', 'ValorAgregado' => true]),
                $card('b', 'Relatório X', 1, 'QA - Reprovado', 'Bug', 'Não Planejado', 0, 3, 'Bruno', 'Crítica', ['Retrabalho' => true]),
                $card('c', 'Ticket 1', 1, 'Implementado', 'Suporte', 'Não Planejado', 2, 2, 'Ana, Gestor', 'Média'),
                $card('d', 'Tarefa cancelada', 1, 'Cancelado', 'Melhoria', 'Planejada', 1, 1, 'Carla', 'Alta'),
                $card('e', 'Integração Hik', 1, 'Aguardando Deploy', 'Melhoria', 'Planejada', 8, 4, 'Bruno', 'Baixa', ['BugProducao' => true]),
                $card('b', 'Relatório X', 2, 'Implementado', 'Bug', 'Não Planejado', 5, 5, 'Bruno', 'Alta'),
                $card('z', 'Login novo (copy)', 2, 'Em progresso', 'Melhoria', 'Planejada', 6, 0, 'Ana', 'Alta', ['Epico' => 'App']),
                $card('y', 'Painel', 2, 'QA - Testes', 'Melhoria', 'Planejada', 3, 1, 'Ana', 'Alta', ['Arrastado' => true]),
                $card('x', 'Novo épico app', 10, 'Implementado', 'Épico', 'Planejada', 4, 6, 'Ana', 'Alta', ['Epico' => 'App']),
            ],
            settings: ['capacityHours' => 80, 'excludedPeople' => ['Gestor'], 'team' => []],
            capacityOverrides: ['1|Ana' => 3000],
        );
    }
}
