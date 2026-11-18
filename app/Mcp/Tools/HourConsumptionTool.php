<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ReportsHours;
use App\Metrics\Consumption;
use App\Models\User;
use App\Screen;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Tela Consumo de horas: horas registradas na sprint, divididas entre Sprint e Suporte, e o consumo da reserva do Bombeiro.
 */
#[Name('hour-consumption')]
#[Description('Tela Consumo de horas: horas registradas (Tempo Real) na sprint, cada card contado uma vez, divididas entre Sprint e Suporte (cards de Tipo Suporte). Traz o total e a participação de cada parte, a composição por Desenvolvimento (Full stack), Projetos, pessoas sem time e cards sem atribuição individual (compartilhados ou sem responsável); o consumo da reserva do Bombeiro (consumido, disponível, excesso e saldo) com o ritmo e o risco de a reserva acabar antes do fim da sprint; os quatro cards de horas do Capacity; as horas por pessoa com os cards; e a qualidade dos dados (cards sem Tipo, sem Tempo Real, sem Id e em várias sprints). Horas com uma casa decimal (null = sem registro); percentuais com uma casa. Sem sprint, usa a mais recente da tela.')]
#[IsReadOnly]
class HourConsumptionTool extends MetricsScreenTool
{
    use ReportsHours;

    protected function screen(): Screen
    {
        return Screen::Consumption;
    }

    protected function data(Request $request, User $user): array
    {
        $data = $this->metrics();
        $sprint = $this->sprintArgument($request) ?? (Consumption::sprints($data)[0] ?? null);
        if ($sprint === null) {
            return ['sprint' => null, 'message' => 'Nenhuma sprint encontrada nos cards do Monday.'];
        }
        $screen = Consumption::for($data)->sprint($data, $sprint);
        $model = $screen['model'];
        $reserve = $screen['reserve'];

        return [
            'sprint' => $sprint,
            'dates' => $screen['dates'],
            'unit' => 'horas',
            'total' => [
                ...$this->totals($model['macro']),
                'sprintSharePercent' => self::percent($screen['shares']['sprint']),
                'supportSharePercent' => self::percent($screen['shares']['support']),
                'composition' => $screen['composition'],
            ],
            'teams' => [
                'fullstack' => $this->totals($model['teams']['fullstack']),
                'projects' => $this->totals($model['teams']['projects']),
                'peopleWithoutTeam' => [...$this->totals($model['outside']), 'cards' => $this->tasks($model['outside']['tasks'])],
                'notIndividuallyAttributed' => [...$this->totals($model['unassigned']), 'cards' => $this->tasks($model['unassigned']['tasks'])],
            ],
            'firefighterReserve' => [
                'reserveHours' => self::hours($reserve['minutes']),
                'consumedHours' => self::hours($model['macro']['support']),
                'consumedPercent' => self::percent($reserve['consumed']),
                'availablePercent' => self::percent($reserve['available']),
                'excessPercent' => self::percent($reserve['excess']),
                'balanceHours' => self::hours($reserve['balance']),
                'projectsSupportHours' => self::hours($screen['projectsSupport']),
                'pace' => ['text' => $screen['reservePace']['text'], 'risk' => $screen['reservePace']['risk'], ...self::pace($screen['reservePace']['pace'])],
            ],
            'capacityCards' => self::capacityCards($screen['summary']['cards']),
            'people' => array_map(fn (array $dev): array => [
                'name' => $dev['name'],
                'team' => $dev['team'],
                'teamLabel' => self::teamLabel($dev['team']),
                ...$this->totals($dev),
                'cards' => $this->tasks($dev['tasks']),
            ], $model['developers']),
            'dataQuality' => [
                'cardsWithoutType' => $model['missingType'],
                'cardsWithoutRealTime' => $model['missingReal'],
                'cardsWithoutId' => $model['missingId'],
                'cardsInSeveralSprints' => $model['multiSprint'],
            ],
            'levelLegend' => self::levelLegend(),
        ];
    }

    /**
     * @param  array{sprint: int|float, support: int|float, total: int|float}  $totals
     * @return array{totalHours: ?float, sprintHours: ?float, supportHours: ?float}
     */
    private function totals(array $totals): array
    {
        return ['totalHours' => self::hours($totals['total']), 'sprintHours' => self::hours($totals['sprint']), 'supportHours' => self::hours($totals['support'])];
    }

    /**
     * Cards de um grupo, com as horas registradas, se o card é de Sprint ou de Suporte e o motivo de estar à parte.
     *
     * @param  list<array{row: array<string, mixed>, group: string, minutes: ?int, reason?: string}>  $tasks
     * @return list<array<string, mixed>>
     */
    private function tasks(array $tasks): array
    {
        return array_map(fn (array $task): array => array_filter([
            'card' => $this->card($task['row']),
            'group' => $task['group'],
            'registeredHours' => self::hours($task['minutes']),
            'reason' => $task['reason'] ?? null,
        ], fn (mixed $value, string $key): bool => $key !== 'reason' || $value !== null, ARRAY_FILTER_USE_BOTH), $tasks);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['sprint' => $schema->integer()->description('Número da sprint. Sem ele, a mais recente da tela Consumo de horas.')];
    }
}
