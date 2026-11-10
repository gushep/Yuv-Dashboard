<?php

namespace App\Http\Controllers;

use App\Theme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tema claro ou escuro escolhido no menu lateral. Vale para qualquer usuário logado, sem permissão de tela.
 */
class ThemeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(
            ['theme' => ['required', Rule::enum(Theme::class)]],
            ['theme.required' => 'Informe o tema.', 'theme.enum' => 'Tema inválido.'],
        );

        $user = $request->user();
        $user->theme = Theme::from($data['theme']);
        $user->save();

        return response()->json(['theme' => $user->theme->value]);
    }
}
