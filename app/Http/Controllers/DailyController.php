<?php

namespace App\Http\Controllers;

use App\Models\DailyAction;
use App\Models\MondayConnection;
use App\Models\RetrospectiveAction;
use App\Services\DailyService;
use App\Services\RetrospectiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Tela Daily Scrum (Daily Hub): o To-Do cumulativo de ações da Daily. Os cards e os status vêm da base sincronizada
 * do Monday; as ações respondem com a Daily atualizada.
 */
class DailyController extends Controller
{
    public function __construct(private DailyService $daily) {}

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['sprint' => ['nullable', 'integer', 'min:1'], 'date' => ['nullable', 'date_format:Y-m-d']]);

        return $this->attempt(fn (): array => $this->daily->state($this->connection(), $request->user(), isset($data['sprint']) ? (int) $data['sprint'] : null, $data['date'] ?? null));
    }

    /** Presença na Daily de hoje (a tela chama a cada poucos segundos): quem está na tela e o tempo da Daily. */
    public function presence(Request $request): JsonResponse
    {
        $data = $request->validate(['sprint' => ['required', 'integer', 'min:1']]);

        return $this->attempt(fn (): array => ['presence' => $this->daily->presence($this->connection(), $request->user(), (int) $data['sprint'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sprint' => ['required', 'integer', 'min:1'],
            'description' => ['required', 'string', 'max:2000'],
            'responsible_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'item_id' => ['nullable', 'string', 'regex:/^\d{1,20}$/'],
        ], [
            'description.required' => 'Descreva a ação ou a questão.',
            'description.max' => 'A ação deve ter no máximo 2.000 caracteres.',
            'responsible_id.exists' => 'Escolha um responsável cadastrado.',
        ]);

        return $this->respond($request, (int) $data['sprint'], fn () => $this->daily->open($this->connection(), $request->user(), (int) $data['sprint'], $data['description'], $data['responsible_id'] ?? null, $data['item_id'] ?? null));
    }

    public function complete(Request $request, DailyAction $action): JsonResponse
    {
        $data = $request->validate(
            ['resolution' => ['required', 'string', 'max:4000'], 'publish' => ['sometimes', 'boolean']],
            ['resolution.required' => 'Descreva a resolução para concluir a ação.', 'resolution.max' => 'A resolução deve ter no máximo 4.000 caracteres.'],
        );

        return $this->respond($request, $action->sprint, fn () => $this->daily->complete($this->connection(), $request->user(), $action, $data['resolution'], (bool) ($data['publish'] ?? false)));
    }

    public function publish(Request $request, DailyAction $action): JsonResponse
    {
        return $this->respond($request, $action->sprint, fn () => $this->daily->publish($this->connection(), $request->user(), $action));
    }

    public function notice(Request $request, DailyAction $action): JsonResponse
    {
        return $this->respond($request, $action->sprint, fn () => $this->daily->notice($this->connection(), $request->user(), $action));
    }

    /** Conclui uma ação de retrospectiva pelo card da Daily (só o responsável e o facilitador da retro). */
    public function completeRetroAction(Request $request, RetrospectiveAction $action, RetrospectiveService $retros): JsonResponse
    {
        $data = $request->validate(
            ['sprint' => ['required', 'integer', 'min:1'], 'result' => ['required', 'string', 'max:4000'], 'signal_reached' => ['nullable', 'boolean']],
            ['result.required' => 'Descreva o resultado para concluir a ação.'],
        );
        $signalReached = isset($data['signal_reached']) ? (bool) $data['signal_reached'] : null;

        return $this->respond($request, (int) $data['sprint'], fn () => $retros->complete($request->user(), $action, $data['result'], $signalReached));
    }

    public function reopen(Request $request, DailyAction $action): JsonResponse
    {
        return $this->respond($request, $action->sprint, fn () => $this->daily->reopen($request->user(), $action));
    }

    public function preview(Request $request, DailyAction $action): JsonResponse
    {
        $data = $request->validate(['resolution' => ['nullable', 'string', 'max:4000']]);

        return response()->json(['html' => $this->daily->preview($action->load('responsible'), (string) ($data['resolution'] ?? ''))]);
    }

    /** Executa a ação e responde com a Daily da sprint no dia pedido. */
    private function respond(Request $request, int $sprint, callable $action): JsonResponse
    {
        return $this->attempt(function () use ($request, $sprint, $action): array {
            $action();

            return $this->daily->state($this->connection(), $request->user(), $sprint, $request->input('date'));
        });
    }

    private function connection(): MondayConnection
    {
        $connection = MondayConnection::current();
        if (blank($connection->token) || blank($connection->board_id)) {
            throw new RuntimeException('Integração com o Monday não configurada.');
        }

        return $connection;
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
