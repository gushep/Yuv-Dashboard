<?php

namespace App\Mcp\Tools;

use App\Metrics\ScrumIndicators;
use App\Models\User;
use App\Screen;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

/**
 * Indicadores Scrum: o antes e depois das mudanças nos períodos escolhidos. Só para o Maestro Scrum.
 */
#[Name('scrum-indicators')]
#[Description('Tela Indicadores Scrum (só Maestro Scrum): compara dois períodos de sprints, "antes" e "após" as mudanças. Sem períodos, usa o padrão da tela: as 3 últimas sprints encerradas são o "após" e as anteriores, o "antes". "before.measure" e "after.measure" trazem as médias por sprint (donePerSprint = cards entregues, cardsPerSprint, supportPerSprint, nonSupportPerSprint = sem suporte, hoursPerSprint em horas) e os percentuais (productivity = entregues ÷ cards; plannedDelivered = planejados entregues; deviation = desvio das horas reais sobre as estimadas; withEstimate; ready = cards prontos; rework; dragged = arrastados; client = com cliente preenchido; supportWithHours). "headline", "hero" e "fronts" são os textos do infográfico, com os selos de variação ("×2,7", "+25 p.p." = pontos percentuais, "−50%", "3× menor"; good indica se melhorou). "note" explica o volume sem os tickets de suporte; "overlap" avisa sprints em comum; "partial" avisa sprint em andamento; "chart" traz por sprint o total, os entregues e a produtividade.')]
#[IsReadOnly]
class ScrumIndicatorsTool extends MetricsScreenTool
{
    private const PERIOD = ['before_from', 'before_to', 'after_from', 'after_to'];

    protected function screen(): Screen
    {
        return Screen::ScrumIndicators;
    }

    protected function data(Request $request, User $user): array
    {
        $arguments = $request->validate(array_fill_keys(self::PERIOD, ['nullable', 'integer', 'between:1,999999']));
        $informed = array_filter(self::PERIOD, fn (string $key): bool => isset($arguments[$key]));
        if ($informed !== [] && count($informed) !== count(self::PERIOD)) {
            throw new RuntimeException('Informe os quatro limites dos períodos (before_from, before_to, after_from e after_to) ou nenhum, para usar o padrão da tela.');
        }
        $indicators = new ScrumIndicators($this->metrics());
        $period = $informed === []
            ? $indicators->defaults()
            : ['beforeFrom' => $arguments['before_from'], 'beforeTo' => $arguments['before_to'], 'afterFrom' => $arguments['after_from'], 'afterTo' => $arguments['after_to']];
        if ($period === null) {
            return ['message' => 'Ainda não há sprints encerradas para comparar.'];
        }
        $before = $indicators->between((int) $period['beforeFrom'], (int) $period['beforeTo']);
        $after = $indicators->between((int) $period['afterFrom'], (int) $period['afterTo']);
        if ($before === [] || $after === []) {
            throw new RuntimeException('Um dos períodos não tem sprints iniciadas. Escolha sprints que já começaram.');
        }

        return ['period' => $period, ...$indicators->compare($before, $after)];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'before_from' => $schema->integer()->min(1)->description('Primeira sprint do período "antes das mudanças".'),
            'before_to' => $schema->integer()->min(1)->description('Última sprint do período "antes das mudanças".'),
            'after_from' => $schema->integer()->min(1)->description('Primeira sprint do período "após as mudanças".'),
            'after_to' => $schema->integer()->min(1)->description('Última sprint do período "após as mudanças".'),
        ];
    }
}
