<?php

namespace App\Http\Controllers;

use App\Models\MondayConnection;
use App\Services\BurndownService;
use App\Services\ControlTowerService;
use App\Services\MondayActivityImporter;
use App\Services\MondayService;
use App\Services\MondaySnapshotService;
use App\Services\SprintReleaseService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;

use function Illuminate\Support\defer;

class MondayController extends Controller
{
    /** Trava que limita a cópia do log de atividades a uma vez por hora. */
    private const ACTIVITY_IMPORT_KEY = 'monday-activity-import';

    /** Abas da tela Tarefas que mostram outros quadros do Monday, e a coluna de configuração de cada uma. */
    private const BOARD_TABS = ['roadmap' => 'roadmap_board_id', 'backlog' => 'backlog_board_id'];

    /** Prefixo do cache de cada aba; descartado quando o status de um card muda pelo dashboard. */
    private const BOARD_TAB_CACHE = 'monday-board-tab-';

    private const FIELDS = [
        'Responsavel', 'Status', 'Prioridade', 'Tipo', 'EstH', 'RealH',
        'Epico', 'Sprint', 'Planejada', 'Retrabalho', 'BugProducao', 'ValorAgregado', 'RemainingH', 'Impedimento',
    ];

    public function config(): JsonResponse
    {
        $connection = MondayConnection::current();

        return response()->json([
            'boardId' => $connection->board_id,
            'roadmapBoardId' => $connection->roadmap_board_id,
            'backlogBoardId' => $connection->backlog_board_id,
            'map' => $connection->column_map ?? [],
            'refreshMinutes' => $connection->refresh_minutes,
            'hasToken' => filled($connection->token),
            'metrics' => $connection->metricSettings(),
        ]);
    }

    public function saveMetricSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'capacityHours' => ['required', 'numeric', 'between:0,200'],
            'excludedPeople' => ['present', 'array', 'max:50'],
            'excludedPeople.*' => ['nullable', 'string', 'max:150'],
        ]);
        $people = array_values(array_unique(array_filter(array_map(fn (?string $name): string => trim((string) $name), $data['excludedPeople']), 'strlen')));
        $connection = MondayConnection::current();
        $connection->metric_settings = ['capacityHours' => round((float) $data['capacityHours'], 2), 'excludedPeople' => $people];
        $connection->save();

        return response()->json(['saved' => true, 'metrics' => $connection->metricSettings()]);
    }

    public function capacity(): JsonResponse
    {
        return response()->json(['overrides' => MondayConnection::current()->capacity_overrides ?? []]);
    }

    public function supportReserves(): JsonResponse
    {
        return response()->json([
            'reserves' => MondayConnection::current()->forecast_settings['supportReserves'] ?? [],
            'defaultMinutes' => 2400,
        ]);
    }

    public function saveSupportReserve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sprint' => ['required', 'regex:/^[1-9]\d{0,5}$/'],
            'minutes' => ['required', 'integer', 'between:0,600000'],
        ]);
        $connectionId = MondayConnection::current()->id;
        $reserves = DB::transaction(function () use ($data, $connectionId): array {
            $connection = MondayConnection::query()->lockForUpdate()->findOrFail($connectionId);
            $settings = $connection->forecast_settings ?? [];
            $settings['supportReserves'][$data['sprint']] = (int) $data['minutes'];
            $connection->forecast_settings = $settings;
            $connection->save();

            return $settings['supportReserves'];
        });

        return response()->json(['saved' => true, 'reserves' => $reserves]);
    }

    public function boards(Request $request, MondayService $monday): JsonResponse
    {
        $request->validate(['token' => ['nullable', 'string', 'max:4096']]);

        return $this->attempt(fn () => ['boards' => $monday->boards($this->token($request))]);
    }

    public function inspect(Request $request, MondayService $monday): JsonResponse
    {
        $data = $request->validate([
            'boardId' => ['required', 'regex:/^\d+$/'],
            'token' => ['nullable', 'string', 'max:4096'],
        ]);

        return $this->attempt(function () use ($monday, $data, $request) {
            $board = $monday->board($this->token($request), $data['boardId']);

            return ['boardName' => $board['name'], 'columns' => $board['columns']];
        });
    }

    public function saveConfig(Request $request, MondayService $monday): JsonResponse
    {
        $data = $request->validate([
            'boardId' => ['required', 'regex:/^\d+$/'],
            'token' => ['nullable', 'string', 'max:4096'],
            'map' => ['required', 'array'],
            'refreshMinutes' => ['nullable', 'integer', Rule::in([0, 5, 10, 15, 30, 60])],
        ]);

        return $this->attempt(function () use ($monday, $data, $request) {
            $token = $this->token($request);
            $board = $monday->board($token, $data['boardId']);
            $validIds = array_column($board['columns'], 'id');
            $map = [];
            foreach (self::FIELDS as $field) {
                $value = (string) ($data['map'][$field] ?? '');
                if ($value !== '' && ! in_array($value, $validIds, true)) {
                    throw new RuntimeException("Coluna inválida para {$field}.");
                }
                $map[$field] = $value;
            }
            if ($map['Sprint'] === '') {
                throw new RuntimeException('Selecione a coluna Sprint.');
            }
            $connection = MondayConnection::current();
            $connection->board_id = $data['boardId'];
            $connection->column_map = $map;
            $connection->refresh_minutes = $data['refreshMinutes'] ?? $connection->refresh_minutes;
            if (filled($request->input('token'))) {
                $connection->token = $token;
            }
            $connection->save();
            Cache::forget(MondaySnapshotService::CACHE_KEY);

            return ['saved' => true, 'boardName' => $board['name'], 'refreshMinutes' => $connection->refresh_minutes];
        });
    }

    public function saveRefresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refreshMinutes' => ['required', 'integer', Rule::in([0, 5, 10, 15, 30, 60])]]);
        $connection = MondayConnection::current();
        $connection->refresh_minutes = $data['refreshMinutes'];
        $connection->save();

        return response()->json(['saved' => true, 'refreshMinutes' => $connection->refresh_minutes]);
    }

    public function saveCapacity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sprint' => ['required', 'regex:/^[1-9]\d{0,5}$/'],
            'developer' => ['required', 'string', 'max:150'],
            'minutes' => ['required', 'integer', 'between:0,12000'],
        ]);
        $connection = MondayConnection::current();
        $overrides = $connection->capacity_overrides ?? [];
        $overrides[$data['sprint'].'|'.trim($data['developer'])] = (int) $data['minutes'];
        $connection->capacity_overrides = $overrides;
        $connection->save();

        return response()->json(['saved' => true, 'overrides' => $overrides]);
    }

    /**
     * Cards do Monday para o dashboard. Sprints encerradas voltam ao estado do último dia, reconstituído pelo log de atividades.
     */
    public function sync(Request $request, MondaySnapshotService $snapshots, MondayActivityImporter $importer): JsonResponse
    {
        $data = $request->validate([
            'force' => ['nullable', 'boolean'],
            'bombeiro' => ['nullable', 'boolean'],
            'sprintPanel' => ['nullable', 'boolean'],
        ]);

        return $this->attempt(function () use ($snapshots, $importer, $data) {
            $connection = MondayConnection::current();
            $ttl = ! empty($data['bombeiro']) || ! empty($data['sprintPanel']) || $connection->refresh_minutes === 0
                ? 60 : $connection->refresh_minutes * 60;
            $snapshot = $snapshots->current($connection, $ttl, ! empty($data['force']));
            if ($snapshots->fetchedFresh) {
                $this->importActivityAfterResponse($importer, $connection);
            }

            return $snapshots->withFrozenSprints($snapshot, $connection);
        });
    }

    public function release(Request $request, MondaySnapshotService $snapshots, MondayActivityImporter $importer, SprintReleaseService $release): JsonResponse
    {
        $data = $request->validate(['sprint' => ['nullable', 'integer', 'between:1,999999']]);
        $snapshot = $this->sync($request, $snapshots, $importer);
        if ($snapshot->getStatusCode() !== 200) {
            return $snapshot;
        }
        $connection = MondayConnection::current();

        return response()->json($release->build($snapshot->getData(true), isset($data['sprint']) ? (int) $data['sprint'] : null, $connection->token, $connection->board_id));
    }

    /**
     * Torre de controle da sprint (atual, ou a informada).
     */
    public function controlTower(Request $request, MondaySnapshotService $snapshots, MondayActivityImporter $importer, ControlTowerService $tower): JsonResponse
    {
        $data = $request->validate(['sprint' => ['nullable', 'integer', 'between:1,999999']]);

        return $this->attempt(function () use ($snapshots, $importer, $tower, $data) {
            $connection = MondayConnection::current();
            $snapshot = $snapshots->current($connection, $connection->refresh_minutes === 0 ? 60 : $connection->refresh_minutes * 60);
            if ($snapshots->fetchedFresh) {
                $this->importActivityAfterResponse($importer, $connection);
            }

            return $tower->build($snapshot, $connection, isset($data['sprint']) ? (int) $data['sprint'] : null);
        });
    }

    /**
     * Burndown da sprint (atual, ou a informada).
     */
    public function burndown(Request $request, MondaySnapshotService $snapshots, MondayActivityImporter $importer, BurndownService $burndown): JsonResponse
    {
        $data = $request->validate(['sprint' => ['nullable', 'integer', 'between:1,999999']]);

        return $this->attempt(function () use ($snapshots, $importer, $burndown, $data) {
            $connection = MondayConnection::current();
            $snapshot = $snapshots->current($connection, $connection->refresh_minutes === 0 ? 60 : $connection->refresh_minutes * 60);
            if ($snapshots->fetchedFresh) {
                $this->importActivityAfterResponse($importer, $connection);
            }

            return $burndown->build($snapshot, $connection, isset($data['sprint']) ? (int) $data['sprint'] : null);
        });
    }

    /**
     * Detalhe de um card (colunas, subitens e comentários), lido direto do Monday.
     */
    public function item(Request $request, string $item, MondayService $monday): JsonResponse
    {
        return $this->attempt(function () use ($request, $monday, $item) {
            $connection = $this->configuredConnection();

            return [
                ...$monday->itemDetails($connection->token, $this->itemBoards($connection), $item),
                'canUpdateItems' => $request->user()->can('update-items'),
                // O limite real de cada anexo é o menor entre o do PHP (upload e POST) e os 50 MB aceitos pela rota.
                'maxUploadBytes' => (int) min(UploadedFile::getMaxFilesize(), 50 * 1024 * 1024),
            ];
        });
    }

    /**
     * Histórico de alterações do card, lido do log de atividades do Monday.
     */
    public function itemActivity(string $item, MondayService $monday): JsonResponse
    {
        return $this->attempt(function () use ($monday, $item) {
            $connection = $this->configuredConnection();

            return ['activity' => $monday->itemActivity($connection->token, array_keys($this->itemBoards($connection)), $item)];
        });
    }

    /**
     * Altera o status do card no Monday. A ação fica registrada no Monday em nome do dono do token e no log da aplicação com o usuário do dashboard.
     */
    public function changeItemStatus(Request $request, string $item, MondayService $monday): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'string', 'max:255']]);

        return $this->attempt(function () use ($request, $monday, $item, $data) {
            $connection = $this->configuredConnection();
            $monday->changeStatus($connection->token, $this->itemBoards($connection), $item, $data['status']);
            Cache::forget(MondaySnapshotService::CACHE_KEY);
            foreach (array_keys(self::BOARD_TABS) as $tab) {
                Cache::forget(self::BOARD_TAB_CACHE.$tab);
            }
            Log::info('Status de card alterado pelo dashboard.', ['item' => $item, 'status' => $data['status'], 'user' => $request->user()->email]);

            return ['saved' => true, 'status' => $data['status']];
        });
    }

    /**
     * Comenta no card do Monday (ex.: resultado de QA). Sem texto, só é aceito quando há anexos a enviar em seguida.
     */
    public function postItemComment(Request $request, string $item, MondayService $monday): JsonResponse
    {
        $request->validate([
            'text' => ['nullable', 'string', 'max:20000', Rule::requiredIf(! $request->boolean('hasAttachments'))],
            'hasAttachments' => ['nullable', 'boolean'],
        ], [
            'text.required' => 'Escreva um comentário ou anexe pelo menos um arquivo.',
            'text.max' => 'O comentário deve ter no máximo 20.000 caracteres.',
        ]);

        return $this->attempt(function () use ($request, $monday, $item) {
            $connection = $this->configuredConnection();
            $updateId = $monday->postComment($connection->token, array_keys($this->itemBoards($connection)), $item, trim((string) $request->input('text')), $request->user()->name);
            Log::info('Comentário publicado pelo dashboard.', ['item' => $item, 'update' => $updateId, 'user' => $request->user()->email]);

            return ['saved' => true, 'updateId' => $updateId];
        });
    }

    /**
     * Anexa um arquivo (ex.: evidência de teste) a um comentário do card. Um arquivo por requisição, para caber no limite de upload do servidor.
     */
    public function attachItemCommentFile(Request $request, string $item, string $update, MondayService $monday): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:51200']], [
            'file.required' => 'Selecione um arquivo.',
            'file.file' => 'O servidor não recebeu o arquivo; ele pode ser maior que o limite de upload configurado no PHP.',
            'file.uploaded' => 'O servidor não recebeu o arquivo; ele pode ser maior que o limite de upload configurado no PHP.',
            'file.max' => 'Cada arquivo deve ter no máximo 50 MB.',
        ]);

        return $this->attempt(function () use ($request, $monday, $item, $update) {
            $connection = $this->configuredConnection();
            try {
                $monday->attachFile($connection->token, array_keys($this->itemBoards($connection)), $item, $update, $request->file('file'));
            } catch (RuntimeException $exception) {
                Log::warning('Anexo recusado ao enviar pelo dashboard.', ['item' => $item, 'update' => $update, 'file' => $request->file('file')->getClientOriginalName(), 'error' => $exception->getMessage()]);

                throw $exception;
            }

            return ['saved' => true];
        });
    }

    /**
     * Quadro de uma aba da tela Tarefas (Roadmap ou Backlog). Lido com o mesmo intervalo de cache das tarefas.
     */
    public function boardTab(Request $request, string $tab, MondayService $monday): JsonResponse
    {
        $data = $request->validate(['force' => ['nullable', 'boolean']]);

        return $this->attempt(function () use ($monday, $data, $tab): array {
            $connection = $this->configuredConnection();
            $boardId = (string) $connection->{self::BOARD_TABS[$tab]};
            if ($boardId === '') {
                return ['configured' => false];
            }
            $ttl = $connection->refresh_minutes === 0 ? 60 : $connection->refresh_minutes * 60;
            $cached = Cache::get(self::BOARD_TAB_CACHE.$tab);
            $fresh = $cached && ($cached['boardId'] ?? null) === $boardId && Carbon::parse($cached['syncedAt'])->diffInSeconds(now(), false) < $ttl;
            if (! $fresh || ! empty($data['force'])) {
                $cached = ['boardId' => $boardId, ...$monday->boardTab($connection->token, $boardId)];
                Cache::put(self::BOARD_TAB_CACHE.$tab, $cached, 3600);
            }

            return ['configured' => true, ...$cached];
        });
    }

    /**
     * Grava os quadros das abas Roadmap e Backlog, conferindo antes que o token consegue lê-los. Vazio desliga a aba.
     */
    public function saveBoardTabs(Request $request, MondayService $monday): JsonResponse
    {
        $data = $request->validate([
            'roadmapBoardId' => ['nullable', 'regex:/^\d+$/'],
            'backlogBoardId' => ['nullable', 'regex:/^\d+$/'],
        ], [
            'roadmapBoardId.regex' => 'Informe o ID numérico do quadro do Roadmap (o número no link do quadro).',
            'backlogBoardId.regex' => 'Informe o ID numérico do quadro do Backlog (o número no link do quadro).',
        ]);

        return $this->attempt(function () use ($monday, $data): array {
            $connection = $this->configuredConnection();
            $names = [];
            foreach (self::BOARD_TABS as $tab => $column) {
                $boardId = (string) ($data[$tab.'BoardId'] ?? '');
                $names[$tab] = $boardId === '' ? null : $monday->board($connection->token, $boardId)['name'];
                $connection->{$column} = $boardId === '' ? null : $boardId;
                Cache::forget(self::BOARD_TAB_CACHE.$tab);
            }
            $connection->save();

            return ['saved' => true, 'roadmapBoardId' => $connection->roadmap_board_id, 'backlogBoardId' => $connection->backlog_board_id, 'boardNames' => $names];
        });
    }

    /**
     * Quadros cujos cards o dashboard lê e atualiza, com a coluna de status mapeada (null: detectada pelo título).
     *
     * @return array<string, ?string>
     */
    private function itemBoards(MondayConnection $connection): array
    {
        $boards = [(string) $connection->board_id => $connection->column_map['Status'] ?? null];
        foreach (self::BOARD_TABS as $column) {
            if (filled($connection->{$column})) {
                $boards[(string) $connection->{$column}] ??= null;
            }
        }

        return $boards;
    }

    private function configuredConnection(): MondayConnection
    {
        $connection = MondayConnection::current();
        if (blank($connection->token) || blank($connection->board_id)) {
            throw new RuntimeException('Integração com o Monday não configurada.');
        }

        return $connection;
    }

    /**
     * Sem servidor com agendador, o uso do dashboard mantém a cópia do histórico em dia.
     * Roda após a resposta e no máximo uma vez por hora; uma falha não afeta o sync.
     */
    private function importActivityAfterResponse(MondayActivityImporter $importer, MondayConnection $connection): void
    {
        if (! Cache::add(self::ACTIVITY_IMPORT_KEY, true, 3600)) {
            return;
        }
        defer(function () use ($importer, $connection): void {
            try {
                $importer->import($connection);
            } catch (ConnectionException|RuntimeException $exception) {
                Cache::forget(self::ACTIVITY_IMPORT_KEY);
                report($exception);
            }
        });
    }

    private function token(Request $request): string
    {
        $token = (string) ($request->input('token') ?: MondayConnection::current()->token);
        if ($token === '') {
            throw new RuntimeException('Informe o token da API.');
        }

        return $token;
    }

    private function attempt(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }
}
