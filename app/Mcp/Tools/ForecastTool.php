<?php

namespace App\Mcp\Tools;

use App\Metrics\Forecast;
use App\Models\User;
use App\Screen;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Previsibilidade: horas que faltam nas tarefas abertas de cada pessoa contra a capacidade que resta na sprint.
 */
#[Name('forecast')]
#[Description('Tela Previsibilidade: para cada pessoa da sprint, as horas que faltam para concluir as tarefas abertas (known) contra a capacidade que resta até o fim da sprint (available), a situação (status: over = acima da capacidade, limit = no limite, unknown = sem previsão, within = dentro, low = baixa carga, empty = sem demandas) e as tarefas que faltam. Traz também as tarefas abertas sem responsável e o resumo por time (Full stack com a reserva do Bombeiro descontada, Projetos). Todos os tempos estão em minutos. Sem sprint, usa a sprint em andamento.')]
#[IsReadOnly]
class ForecastTool extends MetricsScreenTool
{
    protected function screen(): Screen
    {
        return Screen::Forecast;
    }

    protected function data(Request $request, User $user): array
    {
        $data = $this->metrics();
        $sprint = $this->sprintArgument($request) ?? $this->defaultSprint($data);
        if ($sprint === null) {
            return ['sprint' => null, 'message' => 'Nenhuma sprint encontrada nos cards do Monday.'];
        }
        $forecast = Forecast::build($data, $sprint);

        return [
            ...$forecast,
            'statusLabels' => Forecast::LABELS,
            'developers' => array_map(fn (array $developer): array => [
                ...$developer,
                'statusLabel' => Forecast::LABELS[$developer['status']] ?? $developer['status'],
                'tasks' => array_map(fn (array $task): array => [...$task, 'row' => $this->card($task['row'])], $developer['tasks']),
            ], $forecast['developers']),
            'unassigned' => $this->cards($forecast['unassigned']),
            'unit' => 'minutos',
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['sprint' => $schema->integer()->description('Número da sprint. Sem ele, a sprint em andamento.')];
    }
}
