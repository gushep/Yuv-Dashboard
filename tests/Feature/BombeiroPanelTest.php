<?php

namespace Tests\Feature;

use App\Metrics\BombeiroPanel;
use App\Metrics\MetricsData;
use Tests\TestCase;

/**
 * Painel do Bombeiro no servidor, com as regras de public/bombeiro.js (não há teste de cálculo em HTML; o
 * bombeiro-audio.tests.html cobre só o som).
 */
class BombeiroPanelTest extends TestCase
{
    public function test_only_support_tickets_with_id_are_shown_in_the_four_lanes(): void
    {
        $panel = BombeiroPanel::build($this->data(), 17);

        $this->assertSame(17, $panel['sprint']);
        $this->assertSame(['backlog' => 1, 'progress' => 1, 'test' => 1, 'done' => 2], $panel['counts']);
        $this->assertSame(['6', '5'], array_column($panel['lanes']['done'], 'Id'));
        $this->assertSame(5, $panel['total']);
    }

    public function test_lanes_follow_the_priority_and_then_the_name(): void
    {
        $data = new MetricsData([
            $this->ticket('a', 'Zeta', 'Backlog', 'Baixa'), $this->ticket('b', 'Beta', 'Backlog', 'Crítico'),
            $this->ticket('c', 'Alfa', 'Backlog', 'Alta'), $this->ticket('d', 'Ágil', 'Backlog', 'Alta'), $this->ticket('e', 'Sem', 'Backlog', ''),
        ]);

        $this->assertSame(['Beta', 'Ágil', 'Alfa', 'Zeta', 'Sem'], array_column(BombeiroPanel::build($data, 17)['lanes']['backlog'], 'Nome'));
        $this->assertSame(['label' => 'Não informada', 'css' => 'neutral', 'rank' => 4], BombeiroPanel::priorityMeta(null));
        $this->assertSame(['label' => 'Média', 'css' => 'medium', 'rank' => 2], BombeiroPanel::priorityMeta('Média'));
    }

    public function test_status_buckets(): void
    {
        $this->assertSame('backlog', BombeiroPanel::statusBucket('Backlog'));
        $this->assertSame('progress', BombeiroPanel::statusBucket('Pronto pra iniciar'));
        $this->assertSame('test', BombeiroPanel::statusBucket('QA - Reprovado'));
        $this->assertSame('done', BombeiroPanel::statusBucket('Cancelado'));
        $this->assertSame('done', BombeiroPanel::statusBucket('Implementado'));
        $this->assertSame('progress', BombeiroPanel::statusBucket('Aguardando Deploy'));
    }

    public function test_sprint_choice_falls_back_to_the_latest_and_offers_the_tickets_without_sprint(): void
    {
        $data = $this->data();

        $this->assertSame(18, BombeiroPanel::build($data)['sprint']);
        $this->assertSame([18, 17], BombeiroPanel::build($data)['sprints']);
        $this->assertTrue(BombeiroPanel::build($data)['hasUnassigned']);
        $this->assertSame(['9'], array_column(BombeiroPanel::build($data, 'unassigned')['lanes']['backlog'], 'Id'));
        $this->assertSame(7, BombeiroPanel::build($data, 'all')['total']);
        $this->assertSame(18, BombeiroPanel::build($data, 99)['sprint']);
    }

    public function test_the_same_ticket_counts_once_with_its_latest_row(): void
    {
        $data = new MetricsData([$this->ticket('1', 'A', 'Backlog'), $this->ticket('1', 'A', 'Em andamento')]);

        $this->assertSame(['backlog' => 0, 'progress' => 1, 'test' => 0, 'done' => 0], BombeiroPanel::build($data, 17)['counts']);
    }

    private function data(): MetricsData
    {
        return new MetricsData(
            rows: [
                $this->ticket('1', 'Backlog', 'Backlog'), $this->ticket('2', 'Andamento', 'Em andamento'), $this->ticket('3', 'Teste', 'QA - Testes'),
                $this->ticket('5', 'Feito', 'Implementado'), $this->ticket('6', 'Cancelado', 'Cancelado'),
                $this->ticket('', 'Sem Id', 'Backlog'), ['Id' => '7', 'Nome' => 'Melhoria', 'Tipo' => 'Melhoria', 'Status' => 'Backlog', 'Sprint' => 17],
                $this->ticket('8', 'Outra sprint', 'Backlog', 'Alta', 18),
            ],
            unassignedSupport: [$this->ticket('9', 'Sem sprint', 'Backlog', 'Alta', null)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function ticket(string $id, string $name, string $status, string $priority = 'Alta', ?int $sprint = 17): array
    {
        return ['Id' => $id, 'Nome' => $name, 'Tipo' => 'Suporte', 'Status' => $status, 'Prioridade' => $priority, 'Sprint' => $sprint];
    }
}
