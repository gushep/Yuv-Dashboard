<?php

namespace App\Mcp\Tools;

use App\Metrics\Daily;
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

#[Name('daily-metrics')]
#[Description('Daily Scrum: cards por coluna do Kanban, horas planejadas e registradas, capacidade e tendência da sprint e por pessoa, XP, precisão e conquistas. Valores de tempo em minutos. Sem sprint, usa a atual. Pendências, impedimentos e presença registrados estão em daily-actions. Somente leitura; não registra presença.')]
#[IsReadOnly]
class DailyMetricsTool extends MetricsScreenTool
{
    protected function screen(): Screen
    {
        return Screen::DailyScrum;
    }

    protected function data(Request $request, User $user): array
    {
        $arguments = $request->validate(['sprint' => ['nullable', 'integer', 'between:1,999999'], 'person' => ['nullable', 'string', 'max:150']]);
        $data = $this->metrics();
        $sprint = isset($arguments['sprint']) ? (int) $arguments['sprint'] : $this->defaultSprint($data);
        if ($sprint === null) {
            return ['sprint' => null, 'message' => 'Nenhuma sprint encontrada nos cards do Monday.'];
        }
        $daily = new Daily($data);
        $result = $daily->sprint($sprint);
        $person = SprintRules::normalize(trim($arguments['person'] ?? ''));
        if ($person !== '') {
            $result['people'] = array_values(array_filter($result['people'], fn (array $dev): bool => str_contains(SprintRules::normalize($dev['name']), $person)));
            if ($result['people'] === []) {
                throw new RuntimeException('Pessoa não encontrada na Daily desta sprint.');
            }
        }
        $result['kanban'] = array_map(fn (array $row): array => [
            ...$this->card($row), 'column' => Daily::column($row['Status'] ?? null), 'hours' => Daily::cardHours($row), 'remaining' => Daily::remainingOf($row),
        ], array_values(array_filter($daily->sprintCards($sprint), fn (array $row): bool => $person === '' || array_any($result['people'], fn (array $dev): bool => Daily::ownedBy($row, $dev['name'])))));

        return ['unit' => 'minutos', 'syncedAt' => $data->syncedAt, ...$result];
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['sprint' => $this->sprintSchema($schema), 'person' => $schema->string()->description('Parte do nome da pessoa para filtrar o Kanban e os indicadores individuais.')];
    }
}
