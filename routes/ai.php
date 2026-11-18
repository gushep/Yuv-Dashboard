<?php

use App\Http\Middleware\EnsureMcpAccess;
use App\Http\Middleware\RecordMcpCall;
use App\Mcp\Servers\MetricsServer;
use Laravel\Mcp\Facades\Mcp;

// Descoberta do OAuth (/.well-known/*) e registro dinâmico das ferramentas de IA (/oauth/register).
Mcp::oauthRoutes();

// MCP do Metrics: token OAuth válido, permissão "Acesso ao MCP", limite de chamadas por pessoa e auditoria.
Mcp::web('/mcp', MetricsServer::class)
    ->middleware([RecordMcpCall::class, 'auth:api', EnsureMcpAccess::class, 'throttle:mcp'])
    ->name('mcp');
