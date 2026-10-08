<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Libera a rota somente para quem tem acesso à tela informada, ex.: `screen:release`.
 */
class EnsureScreenAccess
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $screen): Response
    {
        Gate::authorize('access-screen', $screen);

        return $next($request);
    }
}
