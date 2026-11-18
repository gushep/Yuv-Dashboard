<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Screen;
use App\Services\MondaySnapshotService;
use App\Support\SprintRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Cards da sprint (tela Tarefas): os mesmos cards que as telas recebem, com as sprints encerradas congeladas no último
 * dia, e filtros simples por status, responsável e tipo.
 */
#[Name('cards')]
#[Description('Cards de uma sprint (atual ou informada), como a tela Tarefas: id, nome, link, status, tipo, responsável, prioridade, épico, planejado ou não, horas estimadas, reais e restantes, retrabalho e bug em produção. Filtros opcionais por status, responsável e tipo (sem diferenciar maiúsculas e acentos). Sprints encerradas mostram o status do último dia da sprint.')]
#[IsReadOnly]
class CardsTool extends ScreenTool
{
    /** Máximo de cards por resposta. */
    private const LIMIT = 300;

    protected function screen(): Screen
    {
        return Screen::RawData;
    }

    protected function data(Request $request, User $user): array
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:100'],
            'responsible' => ['nullable', 'string', 'max:150'],
            'type' => ['nullable', 'string', 'max:100'],
        ]);
        $connection = $this->connection();
        $latest = $this->snapshot($connection);
        $sprint = $this->sprintOrCurrent($latest, $this->sprintArgument($request));
        $snapshot = app(MondaySnapshotService::class)->withFrozenSprints($latest, $connection);
        $matches = fn (?string $value, ?string $filter): bool => blank($filter) || str_contains(SprintRules::normalize($value), SprintRules::normalize($filter));

        $cards = collect($snapshot['rows'] ?? [])
            ->filter(fn (array $row): bool => (int) ($row['Sprint'] ?? 0) === $sprint)
            ->filter(fn (array $row): bool => $matches($row['Status'] ?? null, $filters['status'] ?? null)
                && $matches($row['Responsavel'] ?? null, $filters['responsible'] ?? null)
                && $matches($row['Tipo'] ?? null, $filters['type'] ?? null))
            ->values();

        return [
            'sprint' => $sprint,
            'syncedAt' => $snapshot['syncedAt'] ?? null,
            'total' => $cards->count(),
            'truncated' => $cards->count() > self::LIMIT,
            'cards' => $cards->take(self::LIMIT)->map(fn (array $row): array => [
                'id' => (string) ($row['Id'] ?? ''),
                'name' => $row['Nome'] ?? '',
                'url' => ($row['Url'] ?? '') ?: null,
                'status' => $row['Status'] ?? '',
                'type' => $row['Tipo'] ?? '',
                'responsible' => $row['Responsavel'] ?? '',
                'priority' => $row['Prioridade'] ?? '',
                'epic' => $row['Epico'] ?? '',
                'planned' => $row['Planejada'] ?? '',
                'estimatedHours' => $row['EstH'] ?? null,
                'realHours' => $row['RealH'] ?? null,
                'remainingHours' => $row['RemainingH'] ?? null,
                'rework' => (bool) ($row['Retrabalho'] ?? false),
                'productionBug' => (bool) ($row['BugProducao'] ?? false),
                'blocker' => ($row['Impedimento'] ?? '') ?: null,
                'removedFromScope' => SprintRules::isRemoved($row['Status'] ?? null),
            ])->all(),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sprint' => $this->sprintSchema($schema),
            'status' => $schema->string()->description('Parte do status (ex.: "implementado", "qa", "parado").'),
            'responsible' => $schema->string()->description('Parte do nome do responsável.'),
            'type' => $schema->string()->description('Parte do tipo (ex.: "suporte", "bug", "melhoria").'),
        ];
    }
}
