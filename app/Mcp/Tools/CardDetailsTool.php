<?php

namespace App\Mcp\Tools;

use App\Models\MondayConnection;
use App\Models\User;
use App\Screen;
use App\Services\MondayService;
use App\Services\MondaySnapshotService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Detalhe de um card lido direto do Monday, como o painel do card na tela Tarefas: colunas, subitens, comentários com
 * respostas e anexos e, se pedido, o histórico de alterações.
 */
#[Name('card-details')]
#[Description('Detalhe de um card do Monday pelo id (quadros Tarefas, Roadmap/Funil e Backlog): todas as colunas, subitens, comentários com respostas e anexos e, com "include_activity", o histórico de alterações do card.')]
#[IsReadOnly]
class CardDetailsTool extends ScreenTool
{
    protected function screen(): Screen
    {
        return Screen::RawData;
    }

    protected function data(Request $request, User $user): array
    {
        $data = $request->validate([
            'item' => ['required', 'string', 'regex:/^\d{1,20}$/'],
            'include_activity' => ['nullable', 'boolean'],
        ], ['item.required' => 'Informe o id do card.', 'item.regex' => 'O id do card tem só números.']);
        $connection = $this->connection();
        $monday = app(MondayService::class);
        $boards = $this->itemBoards($connection);
        $details = $monday->itemDetails($connection->token, $boards, $data['item']);
        if (! empty($data['include_activity'])) {
            $details['activity'] = $monday->itemActivity($connection->token, array_keys($boards), $data['item']);
        }

        return $details;
    }

    /**
     * Quadros cujos cards o Metrics lê, com a coluna de status mapeada (a mesma regra do MondayController).
     *
     * @return array<string, ?string>
     */
    private function itemBoards(MondayConnection $connection): array
    {
        $boards = [(string) $connection->board_id => $connection->column_map['Status'] ?? null];
        foreach (MondaySnapshotService::BOARD_TABS as $column) {
            if (filled($connection->{$column})) {
                $boards[(string) $connection->{$column}] ??= null;
            }
        }

        return $boards;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'item' => $schema->string()->required()->description('Id do card no Monday (só números).'),
            'include_activity' => $schema->boolean()->description('Inclui o histórico de alterações do card.'),
        ];
    }
}
