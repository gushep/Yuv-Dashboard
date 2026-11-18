<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Screen;
use App\Services\MondaySnapshotService;
use App\Services\SprintReleaseService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Release da sprint: os cards implementados agrupados por tipo, com o resumo de cada um — os dados da tela.
 */
#[Name('release')]
#[Description('Release da sprint (atual ou informada): cards implementados agrupados por tipo, com módulo, link e o resumo da entrega de cada card.')]
#[IsReadOnly]
class ReleaseTool extends ScreenTool
{
    protected function screen(): Screen
    {
        return Screen::Release;
    }

    protected function data(Request $request, User $user): array
    {
        $connection = $this->connection();
        // A tela usa as sprints encerradas congeladas no último dia, como o /api/sync entrega.
        $snapshot = app(MondaySnapshotService::class)->withFrozenSprints($this->snapshot($connection), $connection);

        return app(SprintReleaseService::class)->build($snapshot, $this->sprintArgument($request), $connection->token, $connection->board_id);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['sprint' => $this->sprintSchema($schema)];
    }
}
