<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Screen;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Quem está conectado: nome, e-mail, telas liberadas e permissões. Serve para a IA saber o que pode consultar.
 */
#[Name('current-account')]
#[Description('Retorna a pessoa conectada ao Yuv Sprint Metrics: nome, e-mail, as telas que ela pode consultar e as permissões (atualizar cards, Maestro Scrum etc.).')]
#[IsReadOnly]
class CurrentAccountTool extends Tool
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Response::json([
            'nome' => $user->name,
            'email' => $user->email,
            'telas' => array_map(fn (string $value): array => ['tela' => $value, 'nome' => Screen::from($value)->label()], $user->screenValues()),
            'permissoes' => [
                'atualizar_cards' => (bool) $user->can_update_items,
                'abrir_tickets' => (bool) $user->can_create_tickets,
                'maestro_scrum' => (bool) $user->can_conduct_planning,
                'estima_na_planning' => (bool) $user->can_estimate_planning,
            ],
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
