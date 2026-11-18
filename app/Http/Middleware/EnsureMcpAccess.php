<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * O MCP só atende quem tem a permissão "Acesso ao MCP". Ela é conferida a cada chamada: retirar a permissão corta o
 * acesso mesmo de um token ainda válido.
 */
class EnsureMcpAccess
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->canUseMcp()) {
            return response()->json(['error' => 'access_denied', 'error_description' => 'Seu usuário não tem a permissão "Acesso ao MCP" no Yuv Sprint Metrics.'], 403);
        }
        if (! $request->user()->tokenCan('mcp:use')) {
            return response()->json(['error' => 'insufficient_scope', 'error_description' => 'O token precisa do escopo mcp:use.'], 403);
        }

        return $next($request);
    }
}
