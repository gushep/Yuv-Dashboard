<?php

namespace App\Mcp\Tools;

use App\Models\MondayConnection;
use App\Models\User;
use App\Screen;
use App\Services\MondaySnapshotService;
use App\Support\SprintRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

/**
 * Histórico do quadro Tarefas para a macroestimativa e o responsável sugerido: um card por id, com as horas estimadas
 * e reais. Os mais recentes (id maior) primeiro. Só leitura.
 */
#[Name('task-history')]
#[Description('Histórico do quadro Tarefas (id, nome, tipo, responsável, horas estimadas e reais, status e sprint), do card mais recente para o mais antigo. Filtre por termos do domínio (separados por | ou vírgula: basta um aparecer no nome) e por id mínimo. Use para a macroestimativa (tempo real de cards parecidos) e o responsável sugerido.')]
#[IsReadOnly]
class TaskHistoryTool extends Tool
{
    private const DEFAULT_LIMIT = 300;

    public function __construct(private MondaySnapshotService $snapshots) {}

    public function shouldRegister(Request $request): bool
    {
        return $request->user() instanceof User && $request->user()->canAccessScreen(Screen::RawData);
    }

    public function handle(Request $request): Response
    {
        if (! $this->shouldRegister($request)) {
            return Response::error('Sua conta não tem acesso à tela Tarefas no Metrics.');
        }
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:300'],
            'min_id' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'between:1,1000'],
        ]);
        try {
            $connection = MondayConnection::current();
            $snapshot = $this->snapshots->latest($connection, max(60, $connection->refresh_minutes * 60));
        } catch (RuntimeException $exception) {
            return Response::error('Não foi possível ler o quadro Tarefas: '.$exception->getMessage());
        }
        $terms = array_values(array_filter(array_map(fn (string $term): string => SprintRules::normalize($term), preg_split('/[|,]/', (string) ($validated['search'] ?? ''))), 'strlen'));
        $minId = (int) ($validated['min_id'] ?? 0);

        $cards = [];
        foreach ($snapshot['rows'] ?? [] as $row) {
            $id = (string) ($row['Id'] ?? '');
            if ($id === '' || isset($cards[$id]) || ! empty($row['AsOf']) || (int) $id < $minId) {
                continue;
            }
            $name = (string) ($row['Nome'] ?? '');
            if ($terms !== [] && ! $this->matches(SprintRules::normalize($name), $terms)) {
                continue;
            }
            $cards[$id] = [
                'id' => $id,
                'nome' => $name,
                'tipo' => (string) ($row['Tipo'] ?? ''),
                'responsavel' => (string) ($row['Responsavel'] ?? ''),
                'horasEstimadas' => $this->hours($row['EstH'] ?? null),
                'horasReais' => $this->hours($row['RealH'] ?? null),
                'status' => (string) ($row['Status'] ?? ''),
                'sprint' => (int) $row['Sprint'],
            ];
        }
        uksort($cards, fn (string $a, string $b): int => strcmp(str_pad($b, 20, '0', STR_PAD_LEFT), str_pad($a, 20, '0', STR_PAD_LEFT)));
        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);

        return Response::json([
            'total' => count($cards),
            'atualizadoEm' => $snapshot['syncedAt'] ?? null,
            'cards' => array_slice(array_values($cards), 0, $limit),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Termos do domínio, separados por | ou vírgula (ex.: "playback|vídeo"). Basta um aparecer no nome do card.'),
            'min_id' => $schema->integer()->description('Id mínimo do card (ex.: 13150000000 para os recentes, 13400000000 para o responsável).'),
            'limit' => $schema->integer()->description('Máximo de cards (padrão 300, até 1000).'),
        ];
    }

    /**
     * @param  list<string>  $terms
     */
    private function matches(string $name, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($name, $term)) {
                return true;
            }
        }

        return false;
    }

    private function hours(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $number = (float) str_replace(',', '.', (string) $value);

        return round($number, 2);
    }
}
