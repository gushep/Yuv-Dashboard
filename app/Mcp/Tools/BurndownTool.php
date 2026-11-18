<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Screen;
use App\Services\BurndownService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Burndown: o que falta por dia útil contra a linha ideal, o resumo da sprint e o SLA do suporte — os dados da tela.
 */
#[Name('burndown')]
#[Description('Burndown da sprint (atual ou informada): cards restantes por dia útil contra a linha ideal, por categoria, resumo (ritmo e previsão) e SLA dos tickets de suporte.')]
#[IsReadOnly]
class BurndownTool extends ScreenTool
{
    protected function screen(): Screen
    {
        return Screen::Burndown;
    }

    protected function data(Request $request, User $user): array
    {
        $connection = $this->connection();

        return app(BurndownService::class)->build($this->snapshot($connection), $connection, $this->sprintArgument($request));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['sprint' => $this->sprintSchema($schema)];
    }
}
