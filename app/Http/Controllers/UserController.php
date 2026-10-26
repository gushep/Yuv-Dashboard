<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Models\User;
use App\Screen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'users' => User::query()->orderBy('name')->get()->map($this->present(...)),
            'screens' => Screen::options(),
        ]);
    }

    public function store(UserRequest $request): JsonResponse
    {
        $user = User::create($request->safe()->only(['name', 'email', 'password', 'screens', 'can_update_items', 'can_create_tickets']));

        return response()->json(['user' => $this->present($user)], 201);
    }

    public function update(UserRequest $request, User $user): JsonResponse
    {
        $user->fill($request->safe()->only(['name', 'email', 'screens', 'can_update_items', 'can_create_tickets']));
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
     * @return array{id: int, name: string, email: string, screens: list<string>, canUpdateItems: bool, canCreateTickets: bool}
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'screens' => $user->screenValues(),
            'canUpdateItems' => (bool) $user->can_update_items,
            'canCreateTickets' => (bool) $user->can_create_tickets,
        ];
    }
}
