<?php

namespace App\Services;

use App\Models\MondayConnection;
use App\Support\SprintRules;
use RuntimeException;

/**
 * Abertura de tickets de suporte no Monday pelo botão Novo Ticket: Tipo Suporte, Status Backlog e na sprint atual.
 */
class SupportTicketService
{
    public const TYPE = 'Suporte';

    public const STATUS = 'Backlog';

    public function __construct(private MondayService $monday, private MondaySnapshotService $snapshots) {}

    /**
     * Prioridades aceitas pelo quadro e a sprint em que o ticket será criado.
     *
     * @return array{priorities: list<string>, sprint: array{number: int, name: string}}
     */
    public function options(MondayConnection $connection): array
    {
        $columns = $this->columns($connection);
        $sprint = $this->currentSprint($connection);

        return ['priorities' => $columns['priorities'], 'sprint' => ['number' => $sprint['number'], 'name' => $sprint['name']]];
    }

    /**
     * Cria o card e registra no histórico dele quem abriu o ticket pelo dashboard.
     *
     * @param  array{title: string, description: string, priority: string, client: string}  $ticket
     * @return array{id: string, url: string, commentId: ?string, sprint: array{number: int, name: string}}
     */
    public function create(MondayConnection $connection, array $ticket, string $author): array
    {
        $columns = $this->columns($connection);
        if (! in_array($ticket['priority'], $columns['priorities'], true)) {
            throw new RuntimeException('Prioridade inválida para este quadro.');
        }
        $sprint = $this->currentSprint($connection);
        $values = [
            $columns['type'] => ['label' => self::TYPE],
            $columns['status'] => ['label' => self::STATUS],
            $columns['priority'] => ['label' => $ticket['priority']],
            $columns['sprint'] => ['item_ids' => [(int) $sprint['itemId']]],
        ];
        foreach (['description' => $ticket['description'], 'client' => $ticket['client']] as $field => $text) {
            $column = $columns[$field];
            if ($column !== null && $text !== '') {
                $values[$column['id']] = $column['type'] === 'long_text' ? ['text' => $text] : $text;
            }
        }

        $mutation = 'mutation ($board: ID!, $name: String!, $values: JSON!) { create_item(board_id: $board, item_name: $name, column_values: $values) { id url } }';
        $created = $this->monday->query((string) $connection->token, $mutation, [
            'board' => (string) $connection->board_id,
            'name' => $ticket['title'],
            'values' => json_encode($values, JSON_UNESCAPED_UNICODE),
        ])['create_item'] ?? null;
        if (empty($created['id'])) {
            throw new RuntimeException('O Monday não confirmou a criação do ticket.');
        }
        $this->snapshots->markStale();

        // O Monday registra o dono do token como criador; o comentário identifica quem abriu, guarda a descrição completa
        // e recebe as imagens da descrição (no Monday, imagens do corpo são anexos do comentário).
        $commentId = null;
        try {
            $commentId = $this->monday->postComment((string) $connection->token, [(string) $connection->board_id], (string) $created['id'],
                "Ticket de suporte aberto pelo botão Novo Ticket.\nCliente: {$ticket['client']}\n\n{$ticket['description']}", $author);
        } catch (RuntimeException $exception) {
            report($exception);
        }

        return [
            'id' => (string) $created['id'], 'url' => (string) ($created['url'] ?? ''), 'commentId' => $commentId,
            'sprint' => ['number' => $sprint['number'], 'name' => $sprint['name']],
        ];
    }

    /**
     * Colunas usadas pelo ticket, conferindo que o quadro tem os rótulos fixos (Suporte e Backlog).
     *
     * @return array{type: string, status: string, priority: string, sprint: string, description: ?array{id: string, type: string}, client: array{id: string, type: string}, priorities: list<string>}
     */
    private function columns(MondayConnection $connection): array
    {
        $map = $connection->column_map ?? [];
        foreach (['Tipo' => 'Tipo', 'Status' => 'Status', 'Prioridade' => 'Prioridade', 'Sprint' => 'Sprint'] as $field => $label) {
            if (empty($map[$field])) {
                throw new RuntimeException("Mapeie a coluna {$label} nas Configurações antes de abrir tickets.");
            }
        }
        $columns = collect($this->monday->boardColumns((string) $connection->token, (string) $connection->board_id));
        $labels = fn (string $columnId): array => array_column($this->monday->statusOptions($columns->firstWhere('id', $columnId) ?? []), 'label');
        if (! in_array(self::TYPE, $labels($map['Tipo']), true)) {
            throw new RuntimeException('A coluna Tipo não tem o rótulo "'.self::TYPE.'".');
        }
        if (! in_array(self::STATUS, $labels($map['Status']), true)) {
            throw new RuntimeException('A coluna Status não tem o rótulo "'.self::STATUS.'".');
        }

        // Cliente é obrigatório no ticket; sem a coluna, o formulário já avisa antes de alguém preencher.
        $client = $this->textColumn($columns->all(), ['cliente']) ?? throw new RuntimeException('O quadro não tem a coluna de texto Cliente.');

        return [
            'type' => $map['Tipo'], 'status' => $map['Status'], 'priority' => $map['Prioridade'], 'sprint' => $map['Sprint'],
            'description' => $this->textColumn($columns->all(), ['descrição', 'descricao']),
            'client' => $client,
            'priorities' => array_values(array_unique($labels($map['Prioridade']))),
        ];
    }

    /**
     * Coluna de texto do quadro encontrada pelo título (sem diferenciar maiúsculas).
     *
     * @param  list<array{id: string, title: string, type: string}>  $columns
     * @param  list<string>  $titles
     * @return ?array{id: string, type: string}
     */
    private function textColumn(array $columns, array $titles): ?array
    {
        $column = collect($columns)->first(fn (array $column): bool => in_array(mb_strtolower(trim($column['title'])), $titles, true)
            && in_array($column['type'], ['text', 'long_text'], true));

        return $column ? ['id' => (string) $column['id'], 'type' => (string) $column['type']] : null;
    }

    /**
     * Sprint atual: a que contém hoje; entre sprints, a próxima a começar; sem próxima, a última iniciada.
     *
     * @return array{number: int, name: string, itemId: string}
     */
    private function currentSprint(MondayConnection $connection): array
    {
        // O cronograma das sprints quase não muda: o último snapshot salvo basta, sem esperar o Monday.
        $snapshot = $this->snapshots->latest($connection, PHP_INT_MAX);
        $today = SprintRules::today();
        $sprints = collect($snapshot['sprints'] ?? [])->sortBy('start')->values();
        $sprint = $sprints->filter(fn (array $s): bool => $s['start'] <= $today && $s['end'] >= $today)->last()
            ?? $sprints->first(fn (array $s): bool => $s['start'] > $today)
            ?? $sprints->filter(fn (array $s): bool => $s['start'] <= $today)->last();
        $itemId = $sprint ? array_search((int) $sprint['number'], $snapshot['sprintItems'] ?? [], true) : false;
        if ($sprint === null || $itemId === false) {
            throw new RuntimeException('Não foi possível identificar a sprint atual no cronograma de sprints do Monday.');
        }

        return ['number' => (int) $sprint['number'], 'name' => (string) $sprint['name'], 'itemId' => (string) $itemId];
    }
}
