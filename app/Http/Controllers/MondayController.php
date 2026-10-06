<?php

namespace App\Http\Controllers;

use App\Models\MondayConnection;
use App\Services\MondayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use RuntimeException;

class MondayController extends Controller
{
    private const CACHE_KEY = 'monday-sync-1';

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
        ]);
    }

    public function capacity(): JsonResponse
    {
        return response()->json(['overrides' => MondayConnection::current()->capacity_overrides ?? []]);
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

    public function sync(Request $request, MondayService $monday): JsonResponse
    {
        $data = $request->validate([
            'force' => ['nullable', 'boolean'],
            'bombeiro' => ['nullable', 'boolean'],
            'sprintPanel' => ['nullable', 'boolean'],
        ]);

        return $this->attempt(function () use ($monday, $data) {
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

            return $result;
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
