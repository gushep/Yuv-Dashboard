<?php

namespace Tests\Feature;

use App\Metrics\MetricsData;
use App\Metrics\SprintPanel;
use Tests\TestCase;

/**
 * Painel da Sprint no servidor, com as regras de public/sprint-panel.js (não há teste de cálculo em HTML).
 */
class SprintPanelMetricsTest extends TestCase
{
    public function test_sprint_cards_without_support_fill_the_four_lanes(): void
    {
        $panel = SprintPanel::build($this->data(), 17);

        $this->assertSame(['backlog' => 2, 'progress' => 1, 'test' => 1, 'done' => 2], $panel['counts']);
        $this->assertSame(['Crítico', 'Pronto'], array_column($panel['lanes']['backlog'], 'Nome'));
        $this->assertSame(6, $panel['total']);
        $this->assertNotContains('S1', array_column(array_merge(...array_values($panel['lanes'])), 'Id'));
    }

    public function test_sprint_choice_falls_back_to_the_latest(): void
    {
        $this->assertSame(18, SprintPanel::build($this->data())['sprint']);
        $this->assertSame([18, 17], SprintPanel::build($this->data())['sprints']);
        $this->assertSame(18, SprintPanel::build($this->data(), 99)['sprint']);
        $this->assertNull(SprintPanel::build(new MetricsData([]))['sprint']);
    }

    public function test_the_same_card_counts_once_with_its_latest_row(): void
    {
        $data = new MetricsData([$this->card('1', 'A', 'Backlog'), $this->card('1', 'A', 'QA - Testes')]);

        $this->assertSame(['backlog' => 0, 'progress' => 0, 'test' => 1, 'done' => 0], SprintPanel::build($data, 17)['counts']);
    }

    private function data(): MetricsData
    {
        return new MetricsData([
            $this->card('1', 'Pronto', 'Pronto pra começar', 'Alta'), $this->card('2', 'Crítico', '', 'Crítica'),
            $this->card('3', 'Andamento', 'Aguardando Deploy'), $this->card('4', 'Teste', 'QA - Aprovado'),
            $this->card('5', 'Feito', 'Implementado'), $this->card('6', 'Cancelado', 'Cancelado'),
            ['Id' => 'S1', 'Nome' => 'Suporte', 'Tipo' => 'Suporte', 'Status' => 'Backlog', 'Sprint' => 17],
            ['Id' => '', 'Nome' => 'Sem Id', 'Tipo' => 'Melhoria', 'Status' => 'Backlog', 'Sprint' => 17],
            $this->card('7', 'Outra sprint', 'Backlog', 'Alta', 18),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(string $id, string $name, string $status, string $priority = 'Média', int $sprint = 17): array
    {
        return ['Id' => $id, 'Nome' => $name, 'Tipo' => 'Melhoria', 'Status' => $status, 'Prioridade' => $priority, 'Sprint' => $sprint];
    }
}
