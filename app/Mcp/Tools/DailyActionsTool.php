<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Screen;
use App\Services\DailyService;
use App\Services\RetrospectiveService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Daily Scrum em modo leitura: as Dailies da sprint com presença, tempo e as pendências e impedimentos registrados
 * (responsável, abertura, fechamento, resolução e card), mais as ações abertas das retrospectivas. Não conta presença
 * na Daily de hoje.
 */
#[Name('daily-actions')]
#[Description('Daily Scrum da sprint (atual ou informada), somente leitura: cada Daily com presentes e duração, e as pendências e impedimentos registrados (responsável, quem abriu, quando, status, resolução, card do Monday e tempo até resolver), mais as ações abertas das retrospectivas.')]
#[IsReadOnly]
class DailyActionsTool extends ScreenTool
{
    protected function screen(): Screen
    {
        return Screen::DailyScrum;
    }

    protected function data(Request $request, User $user): array
    {
        $connection = $this->connection();
        $sprint = $this->sprintOrCurrent($this->snapshot($connection), $this->sprintArgument($request));

        return [
            'sprint' => $sprint,
            'dailies' => app(DailyService::class)->history($sprint),
            'retroActions' => app(RetrospectiveService::class)->openActions($user),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['sprint' => $this->sprintSchema($schema)];
    }
}
