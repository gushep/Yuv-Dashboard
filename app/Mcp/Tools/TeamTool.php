<?php

namespace App\Mcp\Tools;

use App\Metrics\SprintKpis;
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
 * Time: os números de cada pessoa ou, com uma pessoa informada, os indicadores, a capacidade e a evolução dela.
 */
#[Name('team')]
#[Description('Tela Time. Sem pessoa: os números de cada pessoa (tot = cards; done = implementados; open = abertos; rework = com retrabalho; bugs = bugs de produção; allocH = horas reais atribuídas, dividindo as horas de um card entre as pessoas dele; conclusao, reworkPct e aderencia em %; netPrec = desvio médio entre real e estimado em %, precLabel em texto) e as horas reais de cada pessoa por sprint. Com pessoa: os mesmos indicadores dela, o percentual dos cards do time que são dela (pctOfTeam), a capacidade em horas (capacityHours, do Capacity), a ocupação (occupancy = allocH ÷ capacidade, em %), a folga em horas (gap) e a evolução por sprint. A sprint é opcional: sem ela, considera todas as sprints.')]
#[IsReadOnly]
class TeamTool extends MetricsScreenTool
{
    protected function screen(): Screen
    {
        return Screen::Team;
    }

    protected function data(Request $request, User $user): array
    {
        $arguments = $request->validate([
            'person' => ['nullable', 'string', 'max:150'],
            'sprint' => ['nullable', 'integer', 'between:1,999999'],
        ]);
        $kpis = new SprintKpis($data = $this->metrics());
        $sprint = isset($arguments['sprint']) ? (int) $arguments['sprint'] : null;
        if ($sprint !== null && ! in_array($sprint, $data->sprints, true)) {
            throw new RuntimeException("A sprint {$sprint} não tem cards no Metrics. Sprints disponíveis: ".implode(', ', $data->sprints).'.');
        }
        $person = filled($arguments['person'] ?? null) ? $this->person($kpis->devs(), trim($arguments['person'])) : null;

        return $kpis->team($person, $sprint);
    }

    /**
     * Pessoa do time pelo nome (sem diferenciar maiúsculas e acentos); nome desconhecido lista as pessoas válidas.
     *
     * @param  list<string>  $people
     */
    private function person(array $people, string $name): string
    {
        $key = fn (string $value): string => mb_strtolower(trim(iconv('UTF-8', 'ASCII//TRANSLIT', $value) ?: $value));
        foreach ($people as $person) {
            if ($key($person) === $key($name)) {
                return $person;
            }
        }

        throw new RuntimeException("Pessoa \"{$name}\" não encontrada no time. Pessoas disponíveis: ".implode(', ', $people).'.');
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'person' => $schema->string()->description('Nome da pessoa, como aparece no Monday (ex.: Ana Souza). Sem ele, traz todas as pessoas.'),
            'sprint' => $schema->integer()->min(1)->description('Número da sprint. Sem ele, considera todas as sprints.'),
        ];
    }
}
