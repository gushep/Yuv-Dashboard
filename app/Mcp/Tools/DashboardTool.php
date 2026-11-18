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
 * Dashboard: os indicadores de todas as sprints, a série de cada sprint e a composição por tipo e qualidade.
 */
#[Name('dashboard')]
#[Description('Tela Dashboard: indicadores de todos os cards de todas as sprints. "total" traz as contagens (tot = cards no escopo, sem os cancelados/descontinuados/despriorizados; done = implementados; removed = retirados do escopo; planned/unplanned = planejados e não planejados fora do suporte; support = tickets de suporte; rework = com retrabalho; drag = arrastados de sprint anterior; withEst = com estimativa) e os percentuais inteiros (conclusao = done ÷ tot; reworkPct; aderencia = cards com estimativa; arrastPct; cleanPct = escopo planejado). "perSprint" repete os números de cada sprint e "volumeHours" traz as horas reais (RealH) e estimadas (EstH) por origem (planejados, não planejados, suporte, arrastados). "averagePerSprint" é a média de cards por sprint; "types" é a contagem por tipo; "quality" separa os cards sem problema, com retrabalho, com bug de produção e com os dois. Para a lista de cards de cada indicador, use sprint-metrics.')]
#[IsReadOnly]
class DashboardTool extends MetricsScreenTool
{
    protected function screen(): Screen
    {
        return Screen::Dashboard;
    }

    protected function data(Request $request, User $user): array
    {
        $dashboard = (new SprintKpis($this->metrics()))->dashboard();
        // As listas de cards de todas as sprints deixariam a resposta grande demais; ficam na ferramenta sprint-metrics.
        unset($dashboard['total']['lists']);

        return [...$dashboard, 'unit' => 'percentuais inteiros (0 a 100) e horas'];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
