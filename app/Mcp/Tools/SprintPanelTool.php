<?php

namespace App\Mcp\Tools;

use App\Metrics\SprintPanel;
use App\Models\User;
use App\Screen;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Painel da Sprint: cards da sprint, fora o Suporte, nas quatro colunas do painel.
 */
#[Name('sprint-panel')]
#[Description('Painel da Sprint: cards da sprint (fora os tickets de Suporte, que ficam no Painel do Bombeiro) nas colunas backlog (a fazer), progress (em andamento), test (em teste/QA) e done (concluídos), cada coluna ordenada pela prioridade, com a contagem por coluna (counts) e o total. Sem sprint, vale a mais recente, como o painel abre.')]
#[IsReadOnly]
class SprintPanelTool extends MetricsScreenTool
{
    protected function screen(): Screen
    {
        return Screen::SprintPanel;
    }

    protected function data(Request $request, User $user): array
    {
        $panel = SprintPanel::build($this->metrics(), $this->sprintArgument($request));

        return [...$panel, 'lanes' => array_map($this->cards(...), $panel['lanes'])];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['sprint' => $schema->integer()->description('Número da sprint. Sem ele, a sprint mais recente.')];
    }
}
