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
use RuntimeException;

/**
 * Sprints: os indicadores de uma sprint comparados com a anterior e a composição dos cards.
 */
#[Name('sprint-metrics')]
#[Description('Tela Sprints: indicadores de uma sprint ("metrics") e da sprint anterior ("previousMetrics", para a tendência). As contagens e percentuais seguem o Dashboard (tot = cards no escopo; done = implementados; conclusao = done ÷ tot em %; reworkPct, aderencia, arrastPct, cleanPct em %). "metrics.lists" traz os cards de cada indicador como {id, nome, sprint}. Traz também a contagem por status, tipo, prioridade (Crítica, Alta, Média, Baixa), planejado × não planejado, por épico e, por pessoa, total de cards, implementados e % de conclusão. Sem sprint, usa a mesma sprint que a tela abre (a em andamento).')]
#[IsReadOnly]
class SprintMetricsTool extends MetricsScreenTool
{
    protected function screen(): Screen
    {
        return Screen::Sprints;
    }

    protected function data(Request $request, User $user): array
    {
        $data = $this->metrics();
        $sprint = $this->sprintArgument($request) ?? $this->defaultSprint($data);
        if ($sprint === null) {
            return ['sprint' => null, 'message' => 'Nenhuma sprint encontrada nos cards do Monday.'];
        }
        if (! in_array($sprint, $data->sprints, true)) {
            throw new RuntimeException("A sprint {$sprint} não tem cards no Metrics. Sprints disponíveis: ".implode(', ', $data->sprints).'.');
        }

        return (new SprintKpis($data))->sprint($sprint);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['sprint' => $this->sprintSchema($schema)];
    }
}
