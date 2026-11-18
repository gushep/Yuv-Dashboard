<?php

namespace App\Mcp\Support;

use App\Models\MondayConnection;

/**
 * Quadros que recebem Escopo de Consultoria pelo MCP e as colunas de cada um: status de validação, estimativa em
 * horas e responsável. O quadro Tarefas usa as colunas mapeadas nas Configurações; Backlog e Funil de Demandas têm
 * colunas fixas (as mesmas do fluxo de escopo feito na sessão local).
 */
final class ConsultingBoards
{
    public const TASKS = 'tarefas';

    public const BACKLOG = 'backlog';

    public const FUNNEL = 'funil';

    public const KEYS = [self::BACKLOG, self::TASKS, self::FUNNEL];

    public function __construct(private MondayConnection $connection) {}

    /**
     * Configuração do quadro: id no Monday, rótulo do quadro, coluna de status e rótulo de validação, coluna de
     * estimativa (horas) e coluna de responsável (null quando o quadro não tem).
     *
     * @return array{key: string, name: string, boardId: string, statusColumn: ?string, validationLabel: string, estimateColumn: ?string, personColumn: ?string}
     */
    public function config(string $key): array
    {
        $map = $this->connection->column_map ?? [];

        return match ($key) {
            self::TASKS => [
                'key' => $key, 'name' => 'Tarefas', 'boardId' => (string) $this->connection->board_id,
                'statusColumn' => $map['Status'] ?? null, 'validationLabel' => 'Ag. Validação',
                'estimateColumn' => $map['EstH'] ?? null, 'personColumn' => $map['Responsavel'] ?? null,
            ],
            self::BACKLOG => [
                'key' => $key, 'name' => 'Backlog', 'boardId' => (string) $this->connection->backlog_board_id,
                'statusColumn' => 'color_mm7q3yt1', 'validationLabel' => 'Ag. Validação',
                'estimateColumn' => 'numeric_mm8t4ysk', 'personColumn' => 'multiple_person_mm8811a9',
            ],
            self::FUNNEL => [
                'key' => $key, 'name' => 'Funil de Demandas', 'boardId' => (string) $this->connection->roadmap_board_id,
                'statusColumn' => 'bug_status', 'validationLabel' => 'Aguardando Validação',
                'estimateColumn' => 'numeric_mm7yfxbr', 'personColumn' => null,
            ],
        };
    }

    /**
     * Quadros configurados, no formato que o MondayService usa para conferir o quadro do card: id => coluna de status.
     *
     * @return array<string, ?string>
     */
    public function boards(): array
    {
        $boards = [];
        foreach (self::KEYS as $key) {
            $config = $this->config($key);
            if ($config['boardId'] !== '') {
                $boards[$config['boardId']] = $config['statusColumn'];
            }
        }

        return $boards;
    }

    /**
     * Chave do quadro (backlog, tarefas ou funil) pelo id no Monday.
     */
    public function keyOf(?string $boardId): ?string
    {
        foreach (self::KEYS as $key) {
            if ($boardId !== null && $boardId !== '' && $this->config($key)['boardId'] === $boardId) {
                return $key;
            }
        }

        return null;
    }
}
