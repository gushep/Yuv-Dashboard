<?php

namespace Tests\Concerns;

use App\Models\MondayActivityEvent;
use App\Models\MondayConnection;
use Carbon\CarbonImmutable;

/**
 * Cenário com histórico do Monday: hoje é quarta, 07/10/2026; a sprint 16 vai de 14/09 a 25/09 e a 17 de 28/09 a 09/10.
 *
 * - Card 1: passou da sprint 16 para a 17 em 28/09; concluído em 01/10.
 * - Card 2: ficou na 16 e só foi concluído em 30/09, depois do fim efetivo dela (27/09).
 * - Card 3: criado na 16 em 30/09, depois do fim dela.
 * - Card 4: em progresso desde 01/10 (parado há 4 dias úteis).
 * - Card 5: impedido desde 06/10 por mudança em lote (só o índice do rótulo).
 * - Card 6: suporte que entrou na 17 em 05/10.
 * - Card 7: card fora do suporte que entrou na 17 em 02/10, ainda não iniciado.
 */
trait SprintHistoryScenario
{
    protected function configureScenarioConnection(): MondayConnection
    {
        $connection = MondayConnection::current();
        $connection->board_id = '123';
        $connection->token = 'token-secreto';
        $connection->column_map = ['Sprint' => 'sprint', 'Status' => 'status', 'EstH' => 'est', 'RealH' => 'real', 'Planejada' => 'plan', 'Tipo' => 'tipo', 'Prioridade' => 'prio', 'Responsavel' => 'resp'];
        $connection->save();

        return $connection;
    }

    /**
     * @return array<string, mixed>
     */
    protected function scenarioSnapshot(): array
    {
        $row = fn (string $id, int $sprint, string $status, array $extra = []): array => [
            'Id' => $id, 'Url' => "https://monday.com/boards/123/pulses/{$id}", 'Nome' => "Card {$id}", 'Responsavel' => 'Ana Souza',
            'Status' => $status, 'Prioridade' => 'Média', 'Tipo' => 'Melhoria', 'Sprint' => $sprint, 'Epico' => '', 'Planejada' => 'Planejada',
            'Retrabalho' => 0, 'BugProducao' => 0, 'ValorAgregado' => 0, 'EstH' => 4.0, 'RealH' => 2.0, 'RemainingH' => null, 'Impedimento' => '', 'Modulo' => '',
            ...$extra,
        ];

        return [
            'boardName' => 'Tarefas', 'fetched' => 7, 'skipped' => 0, 'unassignedSupport' => [],
            'rows' => [
                $row('1', 17, 'Implementado'),
                $row('2', 16, 'Implementado'),
                $row('3', 16, 'Em progresso'),
                $row('4', 17, 'Em progresso'),
                $row('5', 17, 'Parado'),
                $row('6', 17, 'Em progresso', ['Tipo' => 'Suporte', 'Planejada' => 'Não Planejado', 'RealH' => 6.0]),
                $row('7', 17, 'Backlog', ['Planejada' => 'Não Planejado', 'Prioridade' => 'Baixa']),
            ],
            'sprints' => [
                ['number' => 16, 'name' => 'Sprint 16', 'start' => '2026-09-14', 'end' => '2026-09-25', 'source' => 'Cronograma do sprint no Monday'],
                ['number' => 17, 'name' => 'Sprint 17', 'start' => '2026-09-28', 'end' => '2026-10-09', 'source' => 'Cronograma do sprint no Monday'],
            ],
            'sprintItems' => ['9016' => 16, '9017' => 17],
            'labels' => ['status' => ['7' => 'Parado']],
            'syncedAt' => '2026-10-07T15:00:00+00:00',
        ];
    }

    protected function seedScenarioHistory(): void
    {
        $status = fn (string $text): array => ['label' => ['text' => $text]];
        $sprint = fn (int $pulse): array => ['linkedPulseIds' => [['linkedPulseId' => $pulse]]];
        // Primeiro evento copiado: o histórico cobre a sprint 16 desde 15/09.
        $this->historyEvent('2', 'plan', null, $status('Planejada'), '2026-09-15 09:00');
        $this->historyEvent('1', 'sprint', $sprint(9016), $sprint(9017), '2026-09-28 10:00');
        $this->historyEvent('1', 'status', $status('Em progresso'), $status('Implementado'), '2026-10-01 11:00');
        $this->historyEvent('2', 'status', $status('Em progresso'), $status('Implementado'), '2026-09-30 09:00');
        $this->historyEvent('3', null, null, null, '2026-09-30 14:00', 'create_pulse');
        $this->historyEvent('4', 'status', $status('Pronto para começar'), $status('Em progresso'), '2026-10-01 09:00');
        $this->historyEvent('5', 'status', null, ['index' => 7], '2026-10-06 16:00', 'batch_change_pulses_column_value');
        $this->historyEvent('6', 'sprint', [], $sprint(9017), '2026-10-05 10:00');
        $this->historyEvent('7', 'sprint', [], $sprint(9017), '2026-10-02 10:00');
    }

    protected function historyEvent(string $item, ?string $column, mixed $before, mixed $after, string $localTime, string $event = 'update_column_value'): void
    {
        MondayActivityEvent::factory()->create([
            'board_id' => '123', 'item_id' => $item, 'event' => $event, 'column_id' => $column, 'column_title' => $column,
            'previous_value' => $before, 'value' => $after,
            'data' => ['pulse_id' => (int) $item, 'pulse_name' => "Card {$item}", 'column_id' => $column],
            'occurred_at' => CarbonImmutable::parse($localTime, 'America/Sao_Paulo')->utc(),
        ]);
    }
}
