<?php

namespace App\Mcp\Tools;

use App\Metrics\Okrs;
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

#[Name('okrs')]
#[Description('OKRs do time e por pessoa: produtividade (meta ≥90%), retrabalho (≤10%), uso da capacidade (≥80%) com Full stack, Sustentação e Projetos, XP e precisão da Daily. Cada resultado-chave traz "attainment" (atingimento de 0 a 100%: valor ÷ meta com teto de 100%; retrabalho 100% até 10% e 0% em 20%) e "progress" é o progresso do objetivo (média dos três atingimentos, peso 1 cada), no time e em cada pessoa; histórico por sprint, últimos seis meses e crescimento nas últimas doze sprints. Informe from_sprint e to_sprint juntos ou nenhum para a sprint atual. Períodos incluem apenas sprints iniciadas; tempo em minutos.')]
#[IsReadOnly]
class OkrsTool extends MetricsScreenTool
{
    protected function screen(): Screen
    {
        return Screen::Okrs;
    }

    protected function data(Request $request, User $user): array
    {
        $arguments = $request->validate([
            'from_sprint' => ['nullable', 'integer', 'between:1,999999', 'required_with:to_sprint'],
            'to_sprint' => ['nullable', 'integer', 'between:1,999999', 'required_with:from_sprint'],
            'person' => ['nullable', 'string', 'max:150'],
        ]);
        $data = $this->metrics();
        $okrs = new Okrs($data);
        $current = $this->defaultSprint($data);
        if ($current === null || $okrs->startedSprints() === []) {
            return ['sprints' => [], 'message' => 'Nenhuma sprint iniciada para apresentar OKRs.'];
        }
        $from = (int) ($arguments['from_sprint'] ?? $current);
        $to = (int) ($arguments['to_sprint'] ?? $current);
        if ($okrs->between($from, $to) === []) {
            throw new RuntimeException('O período não tem sprints iniciadas.');
        }
        $result = $okrs->period($from, $to);
        $person = SprintRules::normalize(trim($arguments['person'] ?? ''));
        if ($person !== '') {
            $result['people'] = array_values(array_filter($result['people'], fn (array $dev): bool => str_contains(SprintRules::normalize($dev['name']), $person)));
            if ($result['people'] === []) {
                throw new RuntimeException('Pessoa não encontrada nos OKRs deste período.');
            }
        }

        return ['unit' => 'minutos', 'syncedAt' => $data->syncedAt, ...$result];
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from_sprint' => $schema->integer()->min(1)->description('Primeira sprint do período.'),
            'to_sprint' => $schema->integer()->min(1)->description('Última sprint do período.'),
            'person' => $schema->string()->description('Parte do nome da pessoa.'),
        ];
    }
}
