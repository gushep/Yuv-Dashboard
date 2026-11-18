<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ReportsHours;
use App\Metrics\Capacity;
use App\Models\User;
use App\Screen;
use App\Support\SprintRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

/**
 * Tela Capacity: capacidade e horas da sprint por time e por pessoa, com a reserva do Bombeiro.
 */
#[Name('capacity')]
#[Description('Tela Capacity: capacidade e horas da sprint. Traz os quatro cards de horas (Capacidade da sprint, Full stack para a sprint já descontada a reserva do Bombeiro, Bombeiro/sustentação e Projetos/IA) com previsto (Tempo Estimado) e registrado (Tempo Real) contra a capacidade de cada card, o disponível (negativo = excedido) e o semáforo (green, yellow a partir de 90%, red a partir de 100%); os totais por time; o ritmo do time; e, por pessoa, capacidade, estimado (planejado, não planejado e suporte), registrado, utilização (registrado ÷ capacidade decorrida), entrega (estimado concluído ÷ planejado) e impacto do suporte (horas de suporte ÷ registrado). Cards compartilhados, sem responsável ou de pessoas sem time aparecem à parte. Horas com uma casa decimal; percentuais com uma casa. Sem sprint, usa a mais recente com cards, como a tela.')]
#[IsReadOnly]
class CapacityTool extends MetricsScreenTool
{
    use ReportsHours;

    protected function screen(): Screen
    {
        return Screen::Capacity;
    }

    protected function data(Request $request, User $user): array
    {
        $arguments = $request->validate(['sprint' => ['nullable', 'integer', 'between:1,999999'], 'person' => ['nullable', 'string', 'max:150']]);
        $data = $this->metrics();
        // Como a tela: sem sprint escolhida, a mais recente entre as dos cards.
        $sprint = isset($arguments['sprint']) ? (int) $arguments['sprint'] : ($data->sprints !== [] ? max($data->sprints) : null);
        if ($sprint === null) {
            return ['sprint' => null, 'message' => 'Nenhuma sprint encontrada nos cards do Monday.'];
        }
        $capacity = Capacity::for($data);
        $summary = $capacity->sprintSummary($data, $sprint);
        $developers = $summary['model']['developers'];
        $person = trim((string) ($arguments['person'] ?? ''));
        if ($person !== '') {
            $developers = array_values(array_filter($developers, fn (array $dev): bool => str_contains(SprintRules::normalize($dev['name']), SprintRules::normalize($person))));
            if ($developers === []) {
                throw new RuntimeException("Nenhuma pessoa com \"{$person}\" no nome na sprint {$sprint}.");
            }
        }
        $totals = $summary['totals'];
        $pace = $capacity->teamPace($summary['model']['developers'], $data->datesOf($sprint), $data->today());

        return [
            'sprint' => $sprint,
            'dates' => $data->datesOf($sprint),
            'unit' => 'horas',
            'cards' => self::capacityCards($summary['cards']),
            'teams' => [
                'configured' => $capacity->teamsConfigured(),
                'totalCapacityHours' => self::hours($totals['total']),
                'firefighterReserveHours' => self::hours($totals['reserve']),
                'fullstack' => ['people' => $totals['fullstack']['people'], 'capacityHours' => self::hours($totals['fullstack']['capacity']), 'capacityAfterReserveHours' => self::hours($totals['fullstack']['net']),
                    'estimateHours' => self::hours($totals['fullstack']['estimate']), 'registeredHours' => self::hours($totals['fullstack']['registered'])],
                'projects' => ['people' => $totals['projects']['people'], 'capacityHours' => self::hours($totals['projects']['capacity']),
                    'estimateHours' => self::hours($totals['projects']['estimate']), 'registeredHours' => self::hours($totals['projects']['registered'])],
            ],
            'support' => ['cards' => $summary['support']['cards'], 'estimateHours' => self::hours($summary['support']['estimate']), 'registeredHours' => self::hours($summary['support']['registered'])],
            'outsideTeam' => ['estimateHours' => self::hours($summary['outside']['estimate']), 'registeredHours' => self::hours($summary['outside']['registered'])],
            'teamPace' => ['peopleWithRegisteredHours' => $pace['tracked'], 'registeredHours' => self::hours($pace['registered']), 'capacityHours' => self::hours($pace['capacity']), 'pace' => self::pace($pace['pace'])],
            'people' => array_map($this->person(...), $developers),
            'pending' => array_map(fn (array $item): array => ['card' => $this->card($item['row']), 'reasons' => $item['reasons']], $summary['model']['pending']),
            'unassigned' => $this->cards($summary['model']['unassigned']),
            'peopleWithoutTeam' => $this->cards($summary['model']['outside']),
            'levelLegend' => self::levelLegend(),
        ];
    }

    /**
     * @param  array<string, mixed>  $dev
     * @return array<string, mixed>
     */
    private function person(array $dev): array
    {
        return [
            'name' => $dev['name'],
            'team' => $dev['team'],
            'teamLabel' => self::teamLabel($dev['team']),
            'capacityHours' => self::hours($dev['capacity']),
            'capacityInheritedFromSprint' => $dev['inheritedFrom'],
            'elapsedCapacityHours' => self::hours($dev['elapsed']),
            'plannedEstimateHours' => self::hours($dev['plannedEstimate']),
            'completedEstimateHours' => self::hours($dev['completedEstimate']),
            'unplannedEstimateHours' => self::hours($dev['unplannedEstimate']),
            'supportEstimateHours' => self::hours($dev['supportEstimate']),
            'totalEstimateHours' => self::hours($dev['totalEstimate']),
            'registeredHours' => self::hours($dev['registered']),
            'registeredPlannedHours' => self::hours($dev['realPlanned']),
            'registeredUnplannedHours' => self::hours($dev['realUnplanned']),
            'registeredSupportHours' => self::hours($dev['realSupport']),
            'cardsWithoutEstimateHours' => self::hours($dev['missingEstimate']),
            'utilizationPercent' => self::percent($dev['utilization']),
            'deliveryPercent' => self::percent($dev['delivery']),
            'supportImpactPercent' => self::percent($dev['supportImpact']),
            'estimateLevel' => SprintRules::capacityLevel($dev['totalEstimate'], $dev['capacity']),
            'cards' => $this->cards($dev['rows']),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sprint' => $schema->integer()->description('Número da sprint. Sem ele, a sprint mais recente com cards.'),
            'person' => $schema->string()->description('Parte do nome de uma pessoa, para ver só ela.'),
        ];
    }
}
