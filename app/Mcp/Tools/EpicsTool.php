<?php

namespace App\Mcp\Tools;

use App\Metrics\SprintKpis;
use App\Models\User;
use App\Screen;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Épicos: tasks, horas e sprints de cada épico e a participação dos épicos em cada sprint.
 */
#[Name('epics')]
#[Description('Tela Épicos: para cada épico, a quantidade de tasks (cards com o épico preenchido), os cards do tipo Épico implementados ("done"), as horas reais somadas ("hours", em horas, 1 casa) e as sprints em que aparece. "perSprint" traz, para cada sprint, o percentual inteiro dos cards da sprint que pertencem a cada épico. "items" é o total de cards com épico.')]
#[IsReadOnly]
class EpicsTool extends MetricsScreenTool
{
    protected function screen(): Screen
    {
        return Screen::Epics;
    }

    protected function data(Request $request, User $user): array
    {
        return (new SprintKpis($this->metrics()))->epics();
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
