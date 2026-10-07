<?php

namespace App\Http\Controllers;

use App\Models\MondayConnection;
use App\Services\MondayActivityImporter;
use App\Services\MondayService;
use App\Services\SprintReleaseService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

use function Illuminate\Support\defer;

class MondayController extends Controller
{
    private const CACHE_KEY = 'monday-sync-1';

    /** Trava que limita a cópia do log de atividades a uma vez por hora. */
    private const ACTIVITY_IMPORT_KEY = 'monday-activity-import';

    private const FIELDS = [
        'Responsavel', 'Status', 'Prioridade', 'Tipo', 'EstH', 'RealH',
        'Epico', 'Sprint', 'Planejada', 'Retrabalho', 'BugProducao', 'ValorAgregado', 'RemainingH', 'Impedimento',
    ];

    public function config(): JsonResponse
    {
        $connection = MondayConnection::current();

        return response()->json([
            'boardId' => $connection->board_id,
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
            Cache::forget(self::CACHE_KEY);

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

    public function sync(Request $request, MondayService $monday, MondayActivityImporter $importer): JsonResponse
    {
        $data = $request->validate([
            'force' => ['nullable', 'boolean'],
            'bombeiro' => ['nullable', 'boolean'],
            'sprintPanel' => ['nullable', 'boolean'],
        ]);

        return $this->attempt(function () use ($monday, $importer, $data) {
            $connection = MondayConnection::current();
            if (! $connection->board_id || ! $connection->token || empty($connection->column_map['Sprint'])) {
                throw new RuntimeException('Configure o quadro, token e coluna Sprint antes de atualizar.');
            }
            $ttl = ! empty($data['bombeiro']) || ! empty($data['sprintPanel']) || $connection->refresh_minutes === 0
                ? 60 : $connection->refresh_minutes * 60;
            if (empty($data['force']) && ($cached = Cache::get(self::CACHE_KEY))) {
                $age = Carbon::parse($cached['syncedAt'])->diffInSeconds(now(), false);
                if ($age >= 0 && $age < $ttl) {
                    return $cached;
                }
            }
            $result = $monday->sync($connection->token, $connection->board_id, $connection->column_map ?? []);
            Cache::put(self::CACHE_KEY, $result, 3600);
            $this->importActivityAfterResponse($importer, $connection);

            return $result;
        });
    }

    public function release(Request $request, MondayService $monday, MondayActivityImporter $importer, SprintReleaseService $release): JsonResponse
    {
        $data = $request->validate(['sprint' => ['nullable', 'integer', 'between:1,999999']]);
        $snapshot = $this->sync($request, $monday, $importer);
        if ($snapshot->getStatusCode() !== 200) {
            return $snapshot;
        }
        $connection = MondayConnection::current();

        return response()->json($release->build($snapshot->getData(true), isset($data['sprint']) ? (int) $data['sprint'] : null, $connection->token, $connection->board_id));
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
