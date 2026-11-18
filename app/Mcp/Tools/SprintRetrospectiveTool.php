<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Screen;
use App\Services\RetrospectiveService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Sprint Retrospective em modo leitura: sem sprint, as ações de melhoria (com andamento) e a retro de cada sprint; com
 * sprint, a retro dela (cards, votos, tópicos, ações, números da Daily e resultado da review). Não entra na sala.
 */
#[Name('sprint-retrospective')]
#[Description('Sprint Retrospective (somente leitura). Sem "sprint": indicadores e lista das ações de melhoria (responsável, prazo, status e histórico) e o resumo da retro de cada sprint. Com "sprint": cards por coluna, votos, tópicos discutidos, ações definidas, números da Daily e resultado da review da sprint.')]
#[IsReadOnly]
class SprintRetrospectiveTool extends ScreenTool
{
    protected function screen(): Screen
    {
        return Screen::SprintRetrospective;
    }

    protected function data(Request $request, User $user): array
    {
        $connection = $this->connection();
        $sprint = $this->sprintArgument($request);
        $retros = app(RetrospectiveService::class);

        return $sprint === null ? $retros->overview($connection, $user) : $retros->review($connection, $user, $sprint);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['sprint' => $schema->integer()->min(1)->description('Número da sprint para ver a retro dela. Sem informar, mostra as ações de melhoria e o resumo das retros.')];
    }
}
