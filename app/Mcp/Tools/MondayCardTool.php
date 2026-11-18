<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ConsultingBoards;
use App\Models\MondayConnection;
use App\Models\User;
use App\Screen;
use App\Services\MondayService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

/**
 * Tudo o que existe no card para o escopo: colunas, comentários com respostas, subitens, anexos e a demanda de origem
 * no Funil de Demandas (pela coluna de relação ou, sem vínculo, pelo nome do card). Só leitura.
 */
#[Name('monday-card')]
#[Description('Lê um card do Monday (quadros Backlog, Tarefas ou Funil de Demandas): título, colunas, comentários com respostas, subitens, anexos (links públicos temporários) e a demanda de origem no Funil de Demandas. Use para levantar as informações do Escopo Funcional.')]
#[IsReadOnly]
class MondayCardTool extends Tool
{
    /** Máximo de demandas de origem lidas por card. */
    private const ORIGIN_LIMIT = 3;

    public function __construct(private MondayService $monday) {}

    public function shouldRegister(Request $request): bool
    {
        return $request->user() instanceof User && $request->user()->canAccessScreen(Screen::RawData);
    }

    public function handle(Request $request): Response
    {
        if (! $this->shouldRegister($request)) {
            return Response::error('Sua conta não tem acesso à tela Tarefas no Metrics.');
        }
        $validated = $request->validate(['item_id' => ['required', 'regex:/^\d{1,20}$/']], [
            'item_id.required' => 'Informe o id do card.',
            'item_id.regex' => 'O id do card deve ter só números.',
        ]);
        $connection = MondayConnection::current();
        if (blank($connection->token)) {
            return Response::error('O Monday não está configurado no Yuv Sprint Metrics.');
        }
        $boards = new ConsultingBoards($connection);

        try {
            $card = $this->monday->itemDetails($connection->token, $boards->boards(), $validated['item_id'], allUpdates: true);
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage().' O card precisa estar no Backlog, no Tarefas ou no Funil de Demandas.');
        }
        $boardKey = $boards->keyOf($this->boardIdFromUrl($card['url']));

        return Response::json([
            'quadro' => $boardKey,
            'card' => $card,
            'origem' => $boardKey === ConsultingBoards::FUNNEL ? [] : $this->origins($connection, $boards, $card),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'item_id' => $schema->string()->description('Id do card no Monday.')->required(),
        ];
    }

    /**
     * Demandas de origem no Funil: os cards ligados pela coluna de relação; sem vínculo, os do Funil com o mesmo nome.
     *
     * @param  array<string, mixed>  $card
     * @return list<array<string, mixed>>
     */
    private function origins(MondayConnection $connection, ConsultingBoards $boards, array $card): array
    {
        $funnel = $boards->config(ConsultingBoards::FUNNEL)['boardId'];
        if ($funnel === '') {
            return [];
        }
        $ids = $this->linkedIds($connection->token, $card['id']) ?: $this->sameNameIds($connection->token, $funnel, $card['name']);
        $origins = [];
        foreach (array_slice($ids, 0, self::ORIGIN_LIMIT) as $id) {
            try {
                $origins[] = $this->monday->itemDetails($connection->token, [$funnel => 'bug_status'], $id, allUpdates: true);
            } catch (RuntimeException) {
                // Card ligado de outro quadro: não é demanda do Funil.
            }
        }

        return $origins;
    }

    /**
     * @return list<string>
     */
    private function linkedIds(string $token, string $itemId): array
    {
        $query = 'query ($ids: [ID!]!) { items(ids: $ids) { column_values { ... on BoardRelationValue { linked_item_ids } } } }';
        $values = $this->monday->query($token, $query, ['ids' => [$itemId]])['items'][0]['column_values'] ?? [];

        return array_values(array_unique(array_map('strval', array_merge([], ...array_map(fn (array $value): array => $value['linked_item_ids'] ?? [], $values)))));
    }

    /**
     * @return list<string>
     */
    private function sameNameIds(string $token, string $boardId, string $name): array
    {
        $base = trim((string) preg_replace('/\s*\(copy\)\s*/i', ' ', $name));
        if (mb_strlen($base) < 4) {
            return [];
        }
        $query = 'query ($board: [ID!], $name: CompareValue!) { boards(ids: $board) { items_page(limit: 10, query_params: {rules: [{column_id: "name", compare_value: $name, operator: contains_text}]}) { items { id name } } } }';
        $items = $this->monday->query($token, $query, ['board' => [$boardId], 'name' => $base])['boards'][0]['items_page']['items'] ?? [];

        return array_map(fn (array $item): string => (string) $item['id'], $items);
    }

    private function boardIdFromUrl(string $url): ?string
    {
        return preg_match('#/boards/(\d+)/#', $url, $match) ? $match[1] : null;
    }
}
