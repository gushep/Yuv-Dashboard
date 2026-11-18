<?php

namespace App\Http\Controllers;

use App\Models\McpAuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Laravel\Passport\Token;

/**
 * Ferramentas de IA conectadas: a pessoa vê quais ferramentas autorizou pelo MCP, quando autorizou e o último uso, e
 * revoga o acesso. A administração de usuários revoga as de qualquer pessoa.
 */
class McpConnectionController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('mcp-connections', ['user' => $user, 'connections' => $this->connections($user), 'mcpUrl' => url('/mcp')]);
    }

    public function destroy(Request $request, string $client): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $revoked = $user->revokeMcpAccess($client);

        return redirect()->route('mcp.connections')->with('notice', $revoked > 0 ? 'Acesso revogado. A ferramenta precisará de nova autorização.' : 'Essa ferramenta já não tinha acesso.');
    }

    /**
     * Administração de usuários: revoga todas as ferramentas de IA autorizadas pela pessoa.
     */
    public function revokeForUser(User $user): JsonResponse
    {
        return response()->json(['revoked' => $user->revokeMcpAccess()]);
    }

    /**
     * Uma linha por ferramenta com acesso vigente: token de acesso válido ou renovação ainda possível.
     *
     * @return Collection<int, array{clientId: string, name: string, authorizedAt: ?Carbon, lastUsedAt: ?Carbon}>
     */
    private function connections(User $user): Collection
    {
        $tokens = Token::query()->with(['client', 'refreshToken'])->where('user_id', $user->getKey())->where('revoked', false)->get()
            ->filter(fn (Token $token): bool => $token->expires_at?->isFuture()
                || ($token->refreshToken && ! $token->refreshToken->revoked && $token->refreshToken->expires_at?->isFuture()));
        $lastUse = McpAuditLog::query()->where('user_id', $user->getKey())->whereIn('client_id', $tokens->pluck('client_id')->unique())
            ->selectRaw('client_id, max(created_at) as last_used_at')->groupBy('client_id')->pluck('last_used_at', 'client_id');

        return $tokens->groupBy('client_id')->map(fn (Collection $group, string $clientId): array => [
            'clientId' => $clientId,
            'name' => $group->first()->client?->name ?? 'Ferramenta removida',
            'authorizedAt' => $group->min('created_at'),
            'lastUsedAt' => isset($lastUse[$clientId]) ? Carbon::parse($lastUse[$clientId]) : null,
        ])->sortBy('name')->values();
    }
}
