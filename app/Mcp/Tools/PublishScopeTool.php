<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ConsultingBoards;
use App\Mcp\Support\ScopeMarkdown;
use App\Models\MondayConnection;
use App\Models\User;
use App\Services\MondayService;
use App\Support\SprintRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

/**
 * Publica o Escopo Funcional V1 no card do Monday (Backlog, Tarefas ou Funil de Demandas): comentário assinado com o
 * nome de quem pediu, status de validação do quadro e estimativa/responsável só onde estiverem vazios. Um card tem um
 * único Escopo V1: publicar de novo substitui o comentário existente. Exige a permissão "Atualizar cards" (a mesma
 * das edições de card na tela Tarefas, onde ficam as abas Backlog e Funil).
 */
#[Name('publish-scope')]
#[Description('Publica no card do Monday o Escopo Funcional V1 revisado pelo usuário. Use SOMENTE quando o usuário pedir explicitamente a publicação. Cria o comentário (ou substitui o Escopo V1 que já existir no card), muda o status para o de validação do quadro e preenche estimativa e responsável apenas se estiverem vazios. Exige a permissão de atualizar cards.')]
#[IsReadOnly(false)]
#[IsDestructive(true)]
class PublishScopeTool extends Tool
{
    /** Comentário que já é o Escopo V1 do card. */
    private const SCOPE_PATTERN = '/Escopo\s+(Funcional\s+)?V1\b/iu';

    public function __construct(private MondayService $monday) {}

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user instanceof User || ! $user->canUpdateItems()) {
            return Response::error('Publicar escopos exige a permissão "Atualizar cards" e a tela Tarefas no Yuv Sprint Metrics.');
        }
        $data = $request->validate([
            'item_id' => ['required', 'regex:/^\d{1,20}$/'],
            'board' => ['required', 'in:'.implode(',', ConsultingBoards::KEYS)],
            'scope' => ['required', 'string', 'max:60000'],
            'hours' => ['nullable', 'numeric', 'between:0,1000'],
            'responsible' => ['nullable', 'string', 'max:150'],
            'replace_existing' => ['sometimes', 'boolean'],
        ], [
            'item_id.regex' => 'O id do card deve ter só números.',
            'board.in' => 'Informe o quadro: backlog, tarefas ou funil.',
            'scope.required' => 'Informe o escopo em Markdown.',
        ]);
        $connection = MondayConnection::current();
        $board = (new ConsultingBoards($connection))->config($data['board']);
        if (blank($connection->token) || $board['boardId'] === '') {
            return Response::error("O quadro {$board['name']} não está configurado no Yuv Sprint Metrics.");
        }
        $lock = Cache::lock('mcp:publish-scope:'.$board['boardId'].':'.$data['item_id'], 120);
        if (! $lock->get()) {
            return Response::error('Este card já está sendo publicado. Aguarde a conclusão antes de tentar novamente.');
        }
        try {
            return $this->publish($user, $connection, $board, $data);
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, mixed> $board
     * @param  array<string, mixed>  $data
     */
    private function publish(User $user, MondayConnection $connection, array $board, array $data): Response
    {
        $boards = [$board['boardId'] => $board['statusColumn']];

        try {
            $card = $this->monday->itemDetails($connection->token, $boards, $data['item_id'], allUpdates: true);
        } catch (RuntimeException $exception) {
            return Response::error("O card {$data['item_id']} não foi encontrado no quadro {$board['name']}.");
        }

        $result = ['card' => ['id' => $card['id'], 'nome' => $card['name'], 'url' => $card['url']], 'quadro' => $board['name']];
        $existing = collect($card['updates'])->first(fn (array $update): bool => (bool) preg_match(self::SCOPE_PATTERN, $update['text']));
        if ($existing !== null && ! ($data['replace_existing'] ?? false)) {
            return Response::error('O card já tem um Escopo Funcional V1. Para corrigir, revise com o usuário e informe replace_existing=true.');
        }
        if (! in_array($board['validationLabel'], array_column($card['statusOptions'], 'label'), true)) {
            return Response::error('O quadro não tem o status de validação esperado. Nenhuma alteração foi enviada.');
        }
        try {
            $html = '<p><strong>Escopo Funcional V1</strong></p>'.ScopeMarkdown::toHtml($data['scope']);
            $result['comentario'] = $this->comment($connection->token, $boards, $card, $html, $user->name);
        } catch (RuntimeException $exception) {
            return Response::error('O escopo não foi publicado: '.$exception->getMessage());
        }
        $result['status'] = $this->attempt(function () use ($connection, $boards, $card, $board): string {
            $this->monday->changeStatus($connection->token, $boards, $card['id'], $board['validationLabel']);

            return "alterado para {$board['validationLabel']}";
        });
        $result['estimativa'] = $this->estimate($connection->token, $boards, $card, $board, $data['hours'] ?? null);
        $result['responsavel'] = $this->responsible($connection->token, $boards, $card, $board, $data['responsible'] ?? null);
        $result['completo'] = ! array_any([$result['status'], $result['estimativa'], $result['responsavel']], fn (string $step): bool => str_starts_with($step, 'não alterado:') || str_starts_with($step, 'não preenchido:'));
        if (! $result['completo']) {
            $result['aviso'] = 'O comentário foi publicado, mas há etapas pendentes. Confira o resultado de cada etapa antes de tentar novamente. As operações no Monday não são uma transação única.';
        }

        Log::info('Escopo publicado pelo MCP', ['user_id' => $user->id, 'item_id' => $card['id'], 'board' => $board['key'], 'result' => $result]);

        return Response::json($result);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'item_id' => $schema->string()->description('Id do card no Monday.')->required(),
            'board' => $schema->string()->enum(ConsultingBoards::KEYS)->description('Quadro do card: backlog, tarefas ou funil.')->required(),
            'scope' => $schema->string()->description('O Escopo Funcional V1 completo, em Markdown, já revisado pelo usuário.')->required(),
            'hours' => $schema->number()->description('Estimativa em horas (preenchida só se a coluna estiver vazia).'),
            'responsible' => $schema->string()->description('Nome do responsável sugerido (preenchido só se a coluna estiver vazia; o Funil não tem essa coluna).'),
            'replace_existing' => $schema->boolean()->description('Use true apenas para corrigir um Escopo V1 existente, depois da revisão do usuário. Sem ele, uma segunda publicação é recusada.'),
        ];
    }

    /**
     * Comentário do escopo: substitui o Escopo V1 que o card já tiver; senão cria um novo, assinado.
     *
     * @param  array<string, ?string>  $boards
     * @param  array<string, mixed>  $card
     * @return array{acao: string, updateId: string}
     */
    private function comment(string $token, array $boards, array $card, string $html, string $author): array
    {
        $existing = collect($card['updates'])->first(fn (array $update): bool => (bool) preg_match(self::SCOPE_PATTERN, $update['text']));
        if ($existing === null) {
            return ['acao' => 'criado', 'updateId' => $this->monday->postHtmlComment($token, array_keys($boards), $card['id'], $html, $author)];
        }
        $body = $html.'<p><em>Enviado por '.e($author).' pelo Yuv Sprint Metrics</em></p>';
        $edited = $this->monday->query($token, 'mutation ($id: ID!, $body: String!) { edit_update(id: $id, body: $body) { id } }', ['id' => $existing['id'], 'body' => $body]);
        if ((string) ($edited['edit_update']['id'] ?? '') !== $existing['id']) {
            throw new RuntimeException('o Monday não confirmou a substituição do comentário.');
        }

        return ['acao' => 'substituido', 'updateId' => $existing['id']];
    }

    /**
     * @param  array<string, ?string>  $boards
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $board
     */
    private function estimate(string $token, array $boards, array $card, array $board, mixed $hours): string
    {
        if ($board['estimateColumn'] === null) {
            return 'o quadro não tem coluna de estimativa';
        }
        $current = $this->columnText($card, $board['estimateColumn']);
        if ($current !== '') {
            return "mantida ({$current}h, já preenchida)";
        }
        if ($hours === null) {
            return 'não informada';
        }
        $value = rtrim(rtrim(number_format((float) $hours, 2, '.', ''), '0'), '.');

        return $this->attempt(function () use ($token, $boards, $card, $board, $value): string {
            $this->monday->changeItemColumn($token, $boards, $card['id'], $board['estimateColumn'], $value);

            return "preenchida ({$value}h)";
        });
    }

    /**
     * @param  array<string, ?string>  $boards
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $board
     */
    private function responsible(string $token, array $boards, array $card, array $board, ?string $name): string
    {
        if ($board['personColumn'] === null) {
            return 'o quadro não tem coluna de responsável (fica só no texto do escopo)';
        }
        $current = $this->columnText($card, $board['personColumn']);
        if ($current !== '') {
            return "mantido ({$current}, já preenchido)";
        }
        if ($name === null || trim($name) === '') {
            return 'não informado';
        }

        return $this->attempt(function () use ($token, $boards, $card, $board, $name): string {
            $target = SprintRules::normalize($name);
            $user = collect($this->monday->activeUsers($token))->first(fn (array $user): bool => SprintRules::normalize($user['name']) === $target);
            if ($user === null) {
                return "não preenchido: {$name} não foi encontrado entre os usuários do Monday";
            }
            $this->monday->changeItemColumn($token, $boards, $card['id'], $board['personColumn'], [$user['id']]);

            return "preenchido ({$user['name']})";
        });
    }

    /**
     * @param  array<string, mixed>  $card
     */
    private function columnText(array $card, string $columnId): string
    {
        return trim((string) (collect($card['columns'])->firstWhere('id', $columnId)['text'] ?? ''));
    }

    /**
     * Passo opcional da publicação: a falha vira texto no resultado, sem desfazer o comentário já publicado.
     *
     * @param  callable(): string  $step
     */
    private function attempt(callable $step): string
    {
        try {
            return $step();
        } catch (RuntimeException $exception) {
            return 'não alterado: '.$exception->getMessage();
        }
    }
}
