<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Screen;
use App\Services\SprintReviewService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Sprint Review em modo leitura: sem sprint, o panorama (indicadores das reviews e a review de cada sprint); com
 * sprint, a review dela (objetivo, veredito de cada entrega, feedbacks e demandas). Não entra na sala.
 */
#[Name('sprint-review')]
#[Description('Sprint Review (somente leitura). Sem "sprint": indicadores das reviews (entregas aceitas, com ajustes, recusadas, feedbacks, demandas e objetivos atingidos) e o resumo da review de cada sprint. Com "sprint": objetivo, veredito, motivo e destino de cada entrega, feedbacks, demandas criadas no Monday e tempo por etapa.')]
#[IsReadOnly]
class SprintReviewTool extends ScreenTool
{
    protected function screen(): Screen
    {
        return Screen::SprintReview;
    }

    protected function data(Request $request, User $user): array
    {
        $connection = $this->connection();
        $sprint = $this->sprintArgument($request);
        $reviews = app(SprintReviewService::class);

        return $sprint === null ? $reviews->overview($connection, $user) : $reviews->review($connection, $user, $sprint);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['sprint' => $schema->integer()->min(1)->description('Número da sprint para ver a review dela. Sem informar, mostra o panorama das reviews.')];
    }
}
