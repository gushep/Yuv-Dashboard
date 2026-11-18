<?php

namespace App\Http\Middleware;

use App\Models\McpAuditLog;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Auditoria do MCP: grava cada chamada (quem, por qual ferramenta de IA, o método e a ferramenta do MCP, o status).
 * Falha ao gravar a auditoria não derruba a resposta ao usuário.
 */
class RecordMcpCall
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $status = $exception instanceof AuthenticationException ? 401 : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500);
            $this->record($request, $status);

            throw $exception;
        }

        $this->record($request, $response->getStatusCode());

        return $response;
    }

    private function record(Request $request, int $status): void
    {
        try {
            $call = $this->call($request);
            if ($status >= 400) {
                Log::warning('Chamada MCP recusada', ['user_id' => $request->user()?->getAuthIdentifier(), 'method' => $call['method'], 'tool' => $call['target'], 'status' => $status]);
            }
            if ($request->user() === null) {
                return;
            }
            $token = $request->user()?->token();
            $client = $token?->client;
            McpAuditLog::create([
                'user_id' => $request->user()?->getAuthIdentifier(),
                'client_id' => $client?->getKey(),
                'client_name' => $client?->name,
                'method' => mb_substr($call['method'], 0, 60),
                'tool' => $call['target'] === null ? null : mb_substr($call['target'], 0, 100),
                'status' => $status,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Método JSON-RPC e o alvo dele: a ferramenta (tools/call), o prompt (prompts/get) ou o recurso (resources/read).
     *
     * @return array{method: string, target: ?string}
     */
    private function call(Request $request): array
    {
        $payload = $request->json()->all();
        $message = array_is_list($payload) ? ($payload[0] ?? []) : $payload;
        $method = is_string($message['method'] ?? null) ? $message['method'] : 'desconhecido';
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        $target = $params['name'] ?? $params['uri'] ?? null;

        return ['method' => $method, 'target' => is_string($target) ? $target : null];
    }
}
