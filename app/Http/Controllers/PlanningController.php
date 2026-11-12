<?php

namespace App\Http\Controllers;

use App\Models\MondayConnection;
use App\Models\PlanningRound;
use App\Models\PlanningSession;
use App\Services\DailyService;
use App\Services\PlanningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Tela Planning poker: quem tem a tela senta na mesa e joga as cartas; o Maestro Scrum abre a mesa da sprint, joga os
 * cards do Backlog, revela e grava a estimativa no Monday. As ações respondem com a mesa atualizada, que a tela também
 * consulta a cada poucos segundos.
 */
class PlanningController extends Controller
{
    public function __construct(private PlanningService $planning) {}

    public function show(Request $request): JsonResponse
    {
        return $this->table($request, fn () => null);
    }

    public function history(): JsonResponse
    {
        return $this->attempt(fn (): array => ['sprints' => $this->planning->history($this->connection())]);
    }

    public function openTable(Request $request): JsonResponse
    {
        $data = $request->validate(['sprint' => ['required', 'integer', 'min:1']], ['sprint.required' => 'Escolha a sprint que vai ser planejada.']);

        return $this->table($request, fn () => $this->planning->openTable($this->connection(), $request->user(), (int) $data['sprint']));
    }

    public function closeTable(Request $request, PlanningSession $session): JsonResponse
    {
        $data = $request->validate(
            ['goal' => ['required', 'string', 'max:2000']],
            ['goal.required' => 'Informe o objetivo da sprint para encerrar a planning.', 'goal.max' => 'O objetivo da sprint deve ter no máximo 2.000 caracteres.'],
        );

        return $this->table($request, fn () => $this->planning->closeTable($this->connection(), $session, trim($data['goal'])));
    }

    /** Como foi a planning de uma sprint (só leitura), com as Dailies da sprint. */
    public function review(int $sprint, DailyService $daily): JsonResponse
    {
        return $this->attempt(fn (): array => $this->planning->review($this->connection(), $sprint) + ['dailies' => $daily->history($sprint)]);
    }

    public function playCard(Request $request, PlanningSession $session): JsonResponse
    {
        $data = $request->validate(
            ['item' => ['required', 'string', 'regex:/^\d{1,20}$/'], 'replace' => ['sometimes', 'boolean']],
            ['item.required' => 'Escolha a carta.'],
        );

        return $this->table($request, fn () => $this->planning->playCard($this->connection(), $session, $request->user(), $data['item'], (bool) ($data['replace'] ?? false)));
    }

    public function scope(PlanningRound $round): JsonResponse
    {
        return response()->json($this->planning->scope($round));
    }

    public function startEstimate(Request $request, PlanningRound $round): JsonResponse
    {
        return $this->table($request, fn () => $this->planning->startEstimate($round));
    }

    public function vote(Request $request, PlanningRound $round): JsonResponse
    {
        $data = $request->validate(
            ['hours' => ['present', 'nullable', 'numeric', 'min:0', 'max:999']],
            ['hours.present' => 'Informe as horas ou vote vazio.', 'hours.numeric' => 'Informe as horas em número.', 'hours.min' => 'As horas não podem ser negativas.', 'hours.max' => 'A estimativa máxima é 999h.'],
        );

        return $this->table($request, fn () => $this->planning->vote($round, $request->user(), $data['hours'] === null ? null : (float) $data['hours']));
    }

    public function reveal(Request $request, PlanningRound $round): JsonResponse
    {
        return $this->table($request, fn () => $this->planning->reveal($round));
    }

    public function decide(Request $request, PlanningRound $round): JsonResponse
    {
        $data = $request->validate(
            ['source' => ['required', Rule::in([PlanningRound::SOURCE_AI, PlanningRound::SOURCE_PLANNING])], 'note' => ['nullable', 'string', 'max:2000']],
            ['source.required' => 'Escolha a estimativa.', 'source.in' => 'Escolha a estimativa da IA ou a média do time.', 'note.max' => 'O que o time discutiu deve ter no máximo 2.000 caracteres.'],
        );

        return $this->table($request, fn () => $this->planning->decide($this->connection(), $round, $request->user(), $data['source'], $data['note'] ?? null));
    }

    public function cancel(Request $request, PlanningRound $round): JsonResponse
    {
        return $this->table($request, fn () => $this->planning->cancel($round));
    }

    /** Executa a ação e responde com a mesa como a pessoa vê. */
    private function table(Request $request, callable $action): JsonResponse
    {
        return $this->attempt(function () use ($request, $action): array {
            $action();

            return $this->planning->state($this->connection(), $request->user());
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
