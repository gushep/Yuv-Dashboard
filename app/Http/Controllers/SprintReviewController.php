<?php

namespace App\Http\Controllers;

use App\Models\MondayConnection;
use App\Models\SprintReview;
use App\Models\SprintReviewFeedback;
use App\Models\SprintReviewItem;
use App\Services\SprintReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Tela Sprint Review: tela inicial (resultado e histórico), sala ao vivo e feedbacks. Os passos da sala respondem com a
 * sala atualizada; a demanda de um feedback responde com a tela de onde veio (sala ou modo leitura).
 */
class SprintReviewController extends Controller
{
    public function __construct(private SprintReviewService $reviews) {}

    public function show(Request $request): JsonResponse
    {
        return $this->attempt(fn (): array => $this->reviews->overview($this->connection(), $request->user()));
    }

    public function room(Request $request): JsonResponse
    {
        return $this->attempt(fn (): array => $this->reviews->room($this->connection(), $request->user()));
    }

    public function review(Request $request, int $sprint): JsonResponse
    {
        return $this->attempt(fn (): array => $this->reviews->review($this->connection(), $request->user(), $sprint));
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['sprint' => ['required', 'integer', 'min:1']]);

        return $this->inRoom($request, fn () => $this->reviews->start($this->connection(), $request->user(), (int) $data['sprint']));
    }

    public function step(Request $request, SprintReview $review): JsonResponse
    {
        $data = $request->validate(['step' => ['required', 'integer', 'between:0,'.(count(SprintReviewService::STEPS) - 1)]]);

        return $this->inRoom($request, fn () => $this->reviews->step($request->user(), $review, (int) $data['step']));
    }

    public function focus(Request $request, SprintReview $review): JsonResponse
    {
        $data = $request->validate(['item_id' => ['required', 'string', 'regex:/^\d{1,20}$/']]);

        return $this->inRoom($request, fn () => $this->reviews->focus($this->connection(), $request->user(), $review, $data['item_id']));
    }

    public function decide(Request $request, SprintReview $review): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'string', 'regex:/^\d{1,20}$/'],
            'verdict' => ['sometimes', 'nullable', Rule::in(SprintReviewItem::VERDICTS)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'reason' => ['sometimes', 'nullable', Rule::in(SprintReviewItem::REASONS)],
            'destination' => ['sometimes', 'nullable', Rule::in(SprintReviewItem::DESTINATIONS)],
        ]);
        $itemId = $data['item_id'];
        unset($data['item_id']);

        return $this->inRoom($request, fn () => $this->reviews->decide($this->connection(), $request->user(), $review, $itemId, $data));
    }

    public function goal(Request $request, SprintReview $review): JsonResponse
    {
        $data = $request->validate(['result' => ['required', Rule::in(['yes', 'partial', 'no'])], 'note' => ['nullable', 'string', 'max:2000']]);

        return $this->inRoom($request, fn () => $this->reviews->goal($request->user(), $review, $data['result'], $data['note'] ?? null));
    }

    public function addFeedback(Request $request, SprintReview $review): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'type' => ['required', Rule::in(array_keys(SprintReviewFeedback::TYPES))],
            'given_by' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'item_id' => ['nullable', 'string', 'regex:/^\d{1,20}$/'],
        ], ['text.required' => 'Escreva o feedback.', 'given_by.exists' => 'Escolha quem deu o feedback entre os usuários cadastrados.']);

        return $this->inRoom($request, fn () => $this->reviews->addFeedback($this->connection(), $request->user(), $review, $data));
    }

    public function deleteFeedback(Request $request, SprintReviewFeedback $feedback): JsonResponse
    {
        return $this->inRoom($request, fn () => $this->reviews->deleteFeedback($request->user(), $feedback));
    }

    public function createDemand(Request $request, SprintReviewFeedback $feedback): JsonResponse
    {
        return $this->attempt(function () use ($request, $feedback): array {
            $this->reviews->createDemand($this->connection(), $request->user(), $feedback);
            $review = SprintReview::query()->findOrFail($feedback->sprint_review_id);

            return $review->isOpen() ? $this->reviews->room($this->connection(), $request->user()) : $this->reviews->review($this->connection(), $request->user(), $review->sprint);
        });
    }

    public function close(Request $request, SprintReview $review): JsonResponse
    {
        $data = $request->validate(['metrics' => ['nullable', 'array']]);

        return $this->inRoom($request, fn () => $this->reviews->close($this->connection(), $request->user(), $review, $data['metrics'] ?? []));
    }

    /** Passo da sala: executa e responde com a sala atualizada. */
    private function inRoom(Request $request, callable $step): JsonResponse
    {
        return $this->attempt(function () use ($request, $step): array {
            $step();

            return $this->reviews->room($this->connection(), $request->user());
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
