<?php

namespace App\Http\Controllers;

use App\Models\MondayConnection;
use App\Models\Retrospective;
use App\Models\RetrospectiveAction;
use App\Models\RetrospectiveCard;
use App\Models\RetrospectiveTopic;
use App\Services\RetrospectiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Tela Sprint Retrospective: tela inicial (ações e histórico), sala ao vivo e acompanhamento das ações. Os passos da
 * sala respondem com a sala atualizada; os das ações, com a tela de onde vieram (sala ou tela inicial).
 */
class RetrospectiveController extends Controller
{
    public function __construct(private RetrospectiveService $retros) {}

    public function show(Request $request): JsonResponse
    {
        return $this->attempt(fn (): array => $this->retros->overview($this->connection(), $request->user()));
    }

    public function room(Request $request): JsonResponse
    {
        return $this->attempt(fn (): array => $this->retros->room($request->user()));
    }

    public function review(Request $request, int $sprint): JsonResponse
    {
        return $this->attempt(fn (): array => $this->retros->review($this->connection(), $request->user(), $sprint));
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['sprint' => ['required', 'integer', 'min:1']]);

        return $this->inRoom($request, fn () => $this->retros->start($this->connection(), $request->user(), (int) $data['sprint']));
    }

    public function step(Request $request, Retrospective $retro): JsonResponse
    {
        $data = $request->validate(['step' => ['required', 'integer', 'between:0,'.(count(RetrospectiveService::STEPS) - 1)]]);

        return $this->inRoom($request, fn () => $this->retros->step($request->user(), $retro, (int) $data['step']));
    }

    public function setup(Request $request, Retrospective $retro): JsonResponse
    {
        $data = $request->validate([
            'goal' => ['sometimes', 'required', 'string', 'max:2000'],
            'rules' => ['sometimes', 'array', 'max:8'],
            'rules.*.title' => ['required', 'string', 'max:120'],
            'rules.*.text' => ['required', 'string', 'max:500'],
            'context' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'dependencies' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ], ['goal.required' => 'Informe o objetivo da retro.']);

        return $this->inRoom($request, fn () => $this->retros->setup($request->user(), $retro, $data));
    }

    public function reveal(Request $request, Retrospective $retro): JsonResponse
    {
        return $this->inRoom($request, fn () => $this->retros->reveal($request->user(), $retro));
    }

    public function addCard(Request $request, Retrospective $retro): JsonResponse
    {
        $data = $request->validate(
            ['column' => ['required', Rule::in(RetrospectiveCard::COLUMNS)], 'text' => ['required', 'string', 'max:1000']],
            ['text.required' => 'Escreva a carta antes de adicionar.', 'text.max' => 'A carta deve ter no máximo 1.000 caracteres.'],
        );

        return $this->inRoom($request, fn () => $this->retros->addCard($request->user(), $retro, $data['column'], $data['text']));
    }

    public function deleteCard(Request $request, RetrospectiveCard $card): JsonResponse
    {
        return $this->inRoom($request, fn () => $this->retros->deleteCard($request->user(), $card));
    }

    public function vote(Request $request, RetrospectiveCard $card): JsonResponse
    {
        return $this->inRoom($request, fn () => $this->retros->vote($request->user(), $card));
    }

    public function merge(Request $request, RetrospectiveCard $card): JsonResponse
    {
        $data = $request->validate(['target' => ['required', 'integer', Rule::exists('retrospective_cards', 'id')]]);

        return $this->inRoom($request, fn () => $this->retros->merge($request->user(), $card, RetrospectiveCard::query()->findOrFail($data['target'])));
    }

    public function goalVote(Request $request, Retrospective $retro): JsonResponse
    {
        $data = $request->validate(['vote' => ['required', Rule::in(['yes', 'partial', 'no'])]]);

        return $this->inRoom($request, fn () => $this->retros->goalVote($request->user(), $retro, $data['vote']));
    }

    public function addTopic(Request $request, Retrospective $retro): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:500'], 'question' => ['nullable', 'string', 'max:1000']], ['title.required' => 'Informe o tema.']);

        return $this->inRoom($request, fn () => $this->retros->addTopic($request->user(), $retro, $data['title'], $data['question'] ?? null));
    }

    public function updateTopic(Request $request, RetrospectiveTopic $topic): JsonResponse
    {
        $data = $request->validate(['notes' => ['sometimes', 'nullable', 'string', 'max:4000'], 'focus' => ['sometimes', 'nullable', 'integer', Rule::in([1, 2])]]);

        return $this->inRoom($request, fn () => $this->retros->updateTopic($request->user(), $topic, $data));
    }

    public function addAction(Request $request, Retrospective $retro): JsonResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:2000'],
            'responsible_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'signal' => ['nullable', 'string', 'max:1000'],
            'focus' => ['nullable', 'integer', Rule::in([1, 2])],
            'item_id' => ['nullable', 'string', 'regex:/^\d{1,20}$/'],
        ], [
            'description.required' => 'Descreva a ação.',
            'responsible_id.exists' => 'Escolha um responsável cadastrado.',
            'due_date.date_format' => 'Informe o prazo como data.',
        ]);

        return $this->inRoom($request, fn () => $this->retros->addAction($this->connection(), $request->user(), $retro, $data));
    }

    public function deleteAction(Request $request, RetrospectiveAction $action): JsonResponse
    {
        return $this->inRoom($request, fn () => $this->retros->deleteAction($request->user(), $action));
    }

    public function close(Request $request, Retrospective $retro): JsonResponse
    {
        $data = $request->validate(['metrics' => ['nullable', 'array']]);

        return $this->inRoom($request, fn () => $this->retros->close($request->user(), $retro, $data['metrics'] ?? []));
    }

    public function complete(Request $request, RetrospectiveAction $action): JsonResponse
    {
        $data = $request->validate(
            ['result' => ['required', 'string', 'max:4000'], 'signal_reached' => ['nullable', 'boolean']],
            ['result.required' => 'Descreva o resultado para concluir a ação.'],
        );

        return $this->followUp($request, fn () => $this->retros->complete($request->user(), $action, $data['result'], isset($data['signal_reached']) ? (bool) $data['signal_reached'] : null));
    }

    public function drop(Request $request, RetrospectiveAction $action): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:4000']], ['reason.required' => 'Informe o motivo para descartar a ação.']);

        return $this->followUp($request, fn () => $this->retros->drop($request->user(), $action, $data['reason']));
    }

    public function keep(Request $request, RetrospectiveAction $action): JsonResponse
    {
        $data = $request->validate(['due_date' => ['nullable', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:4000']]);

        return $this->followUp($request, fn () => $this->retros->keep($request->user(), $action, $data['due_date'] ?? null, $data['note'] ?? null));
    }

    public function reopen(Request $request, RetrospectiveAction $action): JsonResponse
    {
        return $this->followUp($request, fn () => $this->retros->reopen($request->user(), $action));
    }

    /** Passo da sala: executa e responde com a sala atualizada. */
    private function inRoom(Request $request, callable $step): JsonResponse
    {
        return $this->attempt(function () use ($request, $step): array {
            $step();

            return $this->retros->room($request->user());
        });
    }

    /** Ação de acompanhamento: responde com a sala (revisão na retro) ou com a tela inicial. */
    private function followUp(Request $request, callable $step): JsonResponse
    {
        return $this->attempt(function () use ($request, $step): array {
            $step();

            return $request->input('from') === 'room' ? $this->retros->room($request->user()) : $this->retros->overview($this->connection(), $request->user());
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
