<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Screen;
use App\Services\DailyService;
use App\Services\PlanningService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Sprint Planning em modo leitura: sem sprint, o histórico das últimas sprints (cards, horas e a mesa de cada uma);
 * com sprint, como foi a planning (objetivo, rodadas, votos e estimativas decididas), como a tela mostra. Não entra
 * na mesa aberta: consultar pelo MCP não conta presença.
 */
#[Name('planning')]
#[Description('Sprint Planning (somente leitura). Sem "sprint": histórico das últimas sprints com cards, horas planejadas e executadas e o resumo da planning de cada uma. Com "sprint": objetivo, rodadas do planning poker com votos, estimativa decidida e quem decidiu, os cards da sprint e as Dailies da sprint.')]
#[IsReadOnly]
class PlanningTool extends ScreenTool
{
    protected function screen(): Screen
    {
        return Screen::Planning;
    }

    protected function data(Request $request, User $user): array
    {
        $connection = $this->connection();
        $sprint = $this->sprintArgument($request);
        $planning = app(PlanningService::class);
        if ($sprint === null) {
            // O histórico com a lista de cards de cada sprint passa de 100 KB; por padrão vai só o resumo de cada sprint.
            $limit = max(1, min(PlanningService::HISTORY_SPRINTS, (int) ($request->get('limit') ?? 6)));
            $withCards = (bool) $request->get('include_cards', false);
            $history = array_slice($planning->history($connection), 0, $limit);

            return ['sprints' => $withCards ? $history : array_map(fn (array $item): array => array_diff_key($item, ['tasks' => true]), $history)];
        }

        return $planning->review($connection, $sprint) + ['dailies' => app(DailyService::class)->history($sprint)];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sprint' => $schema->integer()->min(1)->description('Número da sprint para ver a planning dela. Sem informar, lista o histórico das últimas sprints.'),
            'limit' => $schema->integer()->min(1)->max(PlanningService::HISTORY_SPRINTS)->description('Histórico: quantas sprints, das mais recentes (padrão 6).'),
            'include_cards' => $schema->boolean()->description('Histórico: inclui a lista de cards de cada sprint (resposta bem maior). Padrão: não.'),
        ];
    }
}
