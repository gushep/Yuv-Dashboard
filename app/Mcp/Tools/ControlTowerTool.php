<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Screen;
use App\Services\ControlTowerService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Torre de controle: trajetória da sprint, sinais (escopo, parados, impedidos, suporte, capacidade), ponto de decisão,
 * manobras e alertas — os mesmos dados da tela.
 */
#[Name('control-tower')]
#[Description('Torre de controle da sprint (atual ou informada): situação geral, totais por categoria (planejado, não planejado, suporte), burnup, sinais de escopo, cards parados e impedidos, consumo do suporte, capacidade restante, ponto de decisão, manobras sugeridas e alertas.')]
#[IsReadOnly]
class ControlTowerTool extends ScreenTool
{
    protected function screen(): Screen
    {
        return Screen::ControlTower;
    }

    protected function data(Request $request, User $user): array
    {
        $connection = $this->connection();

        return app(ControlTowerService::class)->build($this->snapshot($connection), $connection, $this->sprintArgument($request));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['sprint' => $this->sprintSchema($schema)];
    }
}
