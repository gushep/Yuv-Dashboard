<?php

namespace App\Mcp\Tools;

use App\Metrics\BombeiroPanel;
use App\Models\User;
use App\Screen;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Painel do Bombeiro: tickets de Suporte nas quatro colunas do painel, ordenados pela prioridade.
 */
#[Name('firefighter-panel')]
#[Description('Painel do Bombeiro (sustentação): tickets de Suporte da sprint nas colunas backlog (a fazer), progress (em andamento), test (em teste/QA) e done (concluídos), cada coluna ordenada pela prioridade, com a contagem por coluna (counts) e o total. O parâmetro sprint aceita o número da sprint, "all" (todas) ou "unassigned" (tickets sem sprint); sem ele, vale a sprint mais recente, como o painel abre.')]
#[IsReadOnly]
class FirefighterPanelTool extends MetricsScreenTool
{
    protected function screen(): Screen
    {
        return Screen::Firefighter;
    }

    protected function data(Request $request, User $user): array
    {
        $choice = $request->validate(['sprint' => ['nullable', 'regex:/^(all|unassigned|[1-9]\d{0,5})$/']], ['sprint.regex' => 'Informe o número da sprint, "all" ou "unassigned".'])['sprint'] ?? null;
        $panel = BombeiroPanel::build($this->metrics(), $choice === null ? null : (is_numeric($choice) ? (int) $choice : (string) $choice));

        return [...$panel, 'lanes' => array_map($this->cards(...), $panel['lanes'])];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['sprint' => $schema->string()->description('Número da sprint, "all" para todas ou "unassigned" para os tickets sem sprint. Sem ele, a sprint mais recente.')];
    }
}
