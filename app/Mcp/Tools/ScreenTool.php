<?php

namespace App\Mcp\Tools;

use App\Models\MondayConnection;
use App\Models\User;
use App\Screen;
use App\Services\MondaySnapshotService;
use App\Support\SprintRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

/**
 * Ferramenta do MCP que corresponde a uma tela do Metrics: só aparece e só responde para quem tem acesso à tela, e
 * devolve os mesmos dados que a tela recebe do servidor. Somente leitura.
 */
abstract class ScreenTool extends Tool
{
    /** Tela do Metrics que libera esta ferramenta. */
    abstract protected function screen(): Screen;

    /**
     * Dados da tela para a pessoa conectada.
     *
     * @return array<string, mixed>
     */
    abstract protected function data(Request $request, User $user): array;

    /**
     * Esconde a ferramenta de quem não tem a tela (a lista do MCP e a chamada direta).
     */
    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->canAccessScreen($this->screen());
    }

    public function handle(Request $request): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->canAccessScreen($this->screen())) {
            return Response::error("Sua conta não tem acesso à tela {$this->screen()->label()} no Metrics.");
        }

        try {
            return Response::json($this->data($request, $user));
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }

    /**
     * Conexão com o Monday já configurada (quadro e token), como os controllers exigem.
     */
    protected function connection(): MondayConnection
    {
        $connection = MondayConnection::current();
        if (blank($connection->token) || blank($connection->board_id)) {
            throw new RuntimeException('Integração com o Monday não configurada.');
        }

        return $connection;
    }

    /**
     * Último snapshot salvo do Monday, como as telas abrem (sem esperar uma nova consulta).
     *
     * @return array<string, mixed>
     */
    protected function snapshot(MondayConnection $connection): array
    {
        return app(MondaySnapshotService::class)->latest($connection, $connection->refresh_minutes === 0 ? 60 : $connection->refresh_minutes * 60);
    }

    /**
     * Sprint pedida ou, sem ela, a atual: a que está em andamento hoje ou, entre as sprints, a última que já começou
     * (a mesma regra da Daily).
     *
     * @param  array<string, mixed>  $snapshot
     */
    protected function sprintOrCurrent(array $snapshot, ?int $sprint): int
    {
        if ($sprint !== null) {
            return $sprint;
        }
        $today = SprintRules::today();
        $sprints = collect($snapshot['sprints'] ?? [])->sortByDesc('start')->values();
        $current = $sprints->first(fn (array $item): bool => $item['start'] <= $today && $item['end'] >= $today)
            ?? $sprints->first(fn (array $item): bool => $item['start'] <= $today);

        return (int) ($current['number'] ?? 0);
    }

    /**
     * Argumento opcional "sprint" das ferramentas que mostram uma sprint.
     */
    protected function sprintSchema(JsonSchema $schema): Type
    {
        return $schema->integer()->min(1)->description('Número da sprint (ex.: 19). Sem informar, usa a sprint atual.');
    }

    /**
     * Número da sprint informado nos argumentos, validado.
     */
    protected function sprintArgument(Request $request): ?int
    {
        $data = $request->validate(['sprint' => ['nullable', 'integer', 'between:1,999999']]);

        return isset($data['sprint']) ? (int) $data['sprint'] : null;
    }
}
