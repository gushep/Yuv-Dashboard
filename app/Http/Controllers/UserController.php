<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Models\User;
use App\Screen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Token;

class UserController extends Controller
{
    /** Permissões que a administração liga e desliga em cada usuário. */
    private const PERMISSIONS = ['can_update_items', 'can_create_tickets', 'can_conduct_planning', 'can_estimate_planning', 'can_use_mcp'];

    public function index(): JsonResponse
    {
        $connections = Token::query()->where('revoked', false)->selectRaw('user_id, count(distinct client_id) as total')->groupBy('user_id')->pluck('total', 'user_id');

        return response()->json([
            'users' => User::query()->orderBy('name')->get()->map(fn (User $user): array => $this->present($user, (int) ($connections[$user->id] ?? 0))),
            'screens' => Screen::options(),
        ]);
    }

    public function store(UserRequest $request): JsonResponse
    {
        $user = User::create($request->safe()->only(['name', 'email', 'password', 'screens', ...self::PERMISSIONS]));

        return response()->json(['user' => $this->present($user)], 201);
    }

    public function update(UserRequest $request, User $user): JsonResponse
    {
        $user->fill($request->safe()->only(['name', 'email', 'screens', ...self::PERMISSIONS]));
        if (filled($request->validated('password'))) {
            $user->password = $request->validated('password');
        }
        $user->save();

        return response()->json(['user' => $this->present($user)]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => 'Você não pode excluir o seu próprio usuário.']);
        }
        $user->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array{id: int, name: string, email: string, screens: list<string>, canUpdateItems: bool, canCreateTickets: bool, canConductPlanning: bool, canEstimatePlanning: bool, canUseMcp: bool, mcpConnections: int}
     */
    private function present(User $user, int $mcpConnections = 0): array
    {
        return [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'screens' => $user->screenValues(),
            'canUpdateItems' => (bool) $user->can_update_items,
            'canCreateTickets' => (bool) $user->can_create_tickets,
            'canConductPlanning' => (bool) $user->can_conduct_planning,
            'canEstimatePlanning' => (bool) $user->can_estimate_planning,
            'canUseMcp' => $user->canUseMcp(),
            'mcpConnections' => $mcpConnections,
        ];
    }
}
