<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\ConsultingScopePrompt;
use App\Mcp\Resources\ConsultingMethodResource;
use App\Mcp\Tools\BurndownTool;
use App\Mcp\Tools\CapacityTool;
use App\Mcp\Tools\CardDetailsTool;
use App\Mcp\Tools\CardsTool;
use App\Mcp\Tools\ControlTowerTool;
use App\Mcp\Tools\CurrentAccountTool;
use App\Mcp\Tools\DailyActionsTool;
use App\Mcp\Tools\DailyMetricsTool;
use App\Mcp\Tools\DashboardTool;
use App\Mcp\Tools\EpicsTool;
use App\Mcp\Tools\FirefighterPanelTool;
use App\Mcp\Tools\ForecastTool;
use App\Mcp\Tools\HourConsumptionTool;
use App\Mcp\Tools\MondayCardTool;
use App\Mcp\Tools\OkrsTool;
use App\Mcp\Tools\PlanningTool;
use App\Mcp\Tools\PublishScopeTool;
use App\Mcp\Tools\ReleaseTool;
use App\Mcp\Tools\ScrumIndicatorsTool;
use App\Mcp\Tools\SprintHoursTool;
use App\Mcp\Tools\SprintMetricsTool;
use App\Mcp\Tools\SprintPanelTool;
use App\Mcp\Tools\SprintRetrospectiveTool;
use App\Mcp\Tools\SprintReviewTool;
use App\Mcp\Tools\TaskHistoryTool;
use App\Mcp\Tools\TeamTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * MCP do Yuv Sprint Metrics: as ferramentas de IA consultam, com o login da pessoa, os mesmos dados das telas que ela
 * vê no Metrics. Cada ferramenta confere o acesso à tela correspondente.
 */
#[Name('Yuv Sprint Metrics')]
#[Version('1.0.0')]
#[Instructions('Dados de gestão ágil do time de desenvolvimento da YUV (sprints, cerimônias, capacity e indicadores), vindos do Monday e das cerimônias registradas no Yuv Sprint Metrics. Responda em português do Brasil. Use os números exatamente como as ferramentas retornam e cite a sprint e o período a que se referem. Comece pela ferramenta current-account para saber quais telas a pessoa pode consultar.')]
class MetricsServer extends Server
{
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        CurrentAccountTool::class,
        ControlTowerTool::class, BurndownTool::class, DashboardTool::class, SprintMetricsTool::class,
        EpicsTool::class, TeamTool::class, CardsTool::class, CardDetailsTool::class,
        PlanningTool::class, DailyActionsTool::class, DailyMetricsTool::class,
        SprintReviewTool::class, SprintRetrospectiveTool::class, OkrsTool::class,
        ScrumIndicatorsTool::class, FirefighterPanelTool::class, SprintPanelTool::class,
        CapacityTool::class, SprintHoursTool::class, ForecastTool::class, HourConsumptionTool::class, ReleaseTool::class,
        MondayCardTool::class, TaskHistoryTool::class, PublishScopeTool::class,
    ];

    protected array $resources = [
        ConsultingMethodResource::class,
    ];

    protected array $prompts = [
        ConsultingScopePrompt::class,
    ];
}
