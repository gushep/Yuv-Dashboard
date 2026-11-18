<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ReportsHours;
use App\Metrics\HoursStrip;
use App\Models\User;
use App\Screen;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

/**
 * Horas da sprint da barra superior das telas Capacity, Consumo de horas, Torre de controle e Daily Scrum.
 */
#[Name('sprint-hours')]
#[Description('Horas da sprint, a mesma tabela da barra superior das telas Capacity, Consumo de horas, Torre de controle e Daily Scrum (disponível para quem tem acesso a qualquer uma delas). Para cada parte da capacidade (Capacidade da sprint, Full stack já descontada a reserva do Bombeiro, Bombeiro/sustentação e Projetos/IA): a capacidade e, nas linhas Planejado (Tempo Estimado) e Realizado (Tempo Real), as horas usadas, o percentual da capacidade (null sem capacidade), o disponível (negativo = excedido) e o semáforo (green, yellow a partir de 90%, red a partir de 100%). Horas com uma casa decimal. Sem sprint, usa a sprint em andamento.')]
#[IsReadOnly]
class SprintHoursTool extends MetricsScreenTool
{
    use ReportsHours;

    /** Telas que mostram a barra de horas da sprint: basta acesso a uma delas. */
    public const SCREENS = [Screen::Capacity, Screen::Consumption, Screen::ControlTower, Screen::DailyScrum];

    protected function screen(): Screen
    {
        return Screen::Capacity;
    }

    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $this->allowed($user);
    }

    public function handle(Request $request): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $this->allowed($user)) {
            return Response::error('Sua conta não tem acesso às telas Capacity, Consumo de horas, Torre de controle ou Daily Scrum no Metrics.');
        }

        try {
            return Response::json($this->data($request, $user));
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }

    protected function data(Request $request, User $user): array
    {
        $data = $this->metrics();
        $sprint = $this->sprintArgument($request) ?? $this->defaultSprint($data);
        if ($sprint === null) {
            return ['sprint' => null, 'message' => 'Nenhuma sprint encontrada nos cards do Monday.'];
        }
        $cell = fn (array $cell): array => [
            'usedHours' => self::hours($cell['used']),
            'percent' => $cell['percent'],
            'availableHours' => self::hours($cell['available']),
            'level' => $cell['level'],
        ];

        return [
            'sprint' => $sprint,
            'dates' => $data->datesOf($sprint),
            'unit' => 'horas',
            'firefighterReserveHours' => self::hours(HoursStrip::reserveFor($data, $sprint)),
            'columns' => array_map(fn (array $column): array => [
                'label' => $column['label'],
                'shortLabel' => $column['shortLabel'],
                'kind' => $column['kind'] === '' ? 'total' : $column['kind'],
                'capacityHours' => self::hours($column['capacity']),
                'planned' => $cell($column['planned']),
                'realized' => $cell($column['realized']),
            ], HoursStrip::table($data, $sprint)['columns']),
            'levelLegend' => self::levelLegend(),
        ];
    }

    private function allowed(User $user): bool
    {
        foreach (self::SCREENS as $screen) {
            if ($user->canAccessScreen($screen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['sprint' => $schema->integer()->description('Número da sprint. Sem ele, a sprint em andamento.')];
    }
}
