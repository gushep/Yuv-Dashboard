<?php

namespace App\Http\Controllers;

use App\Models\MondayConnection;
use App\Services\MondayService;
use App\Services\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Botão Novo Ticket (telas Tarefas e Painel do Bombeiro): abre tickets de suporte no Monday.
 */
class SupportTicketController extends Controller
{
    /** Tickets abertos nesta sessão (card:comentário) que ainda podem receber as imagens da descrição. */
    private const OPENED_TICKETS = 'opened-tickets';

    public function options(SupportTicketService $tickets): JsonResponse
    {
        return $this->attempt(fn (): array => [
            ...$tickets->options($this->connection()),
            // O limite real de cada anexo é o menor entre o do PHP (upload e POST) e os 50 MB aceitos pela rota.
            'maxUploadBytes' => (int) min(UploadedFile::getMaxFilesize(), 50 * 1024 * 1024),
        ]);
    }

    public function store(Request $request, SupportTicketService $tickets): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'priority' => ['required', 'string', 'max:255'],
            'client' => ['required', 'string', 'max:255'],
        ], [
            'client.required' => 'Informe o cliente.',
            'client.max' => 'O cliente deve ter no máximo 255 caracteres.',
            'title.required' => 'Informe o título do ticket.',
            'title.max' => 'O título deve ter no máximo 255 caracteres.',
            'description.required' => 'Informe a descrição do ticket.',
            'description.max' => 'A descrição deve ter no máximo 10.000 caracteres.',
            'priority.required' => 'Selecione a prioridade.',
        ]);

        return $this->attempt(function () use ($tickets, $data, $request): array {
            $ticket = $tickets->create($this->connection(), [
                'title' => trim($data['title']), 'description' => trim($data['description']), 'priority' => $data['priority'],
                'client' => trim($data['client']),
            ], $request->user()->name);
            Log::info('Ticket aberto pelo dashboard.', ['item' => $ticket['id'], 'sprint' => $ticket['sprint']['number'], 'user' => $request->user()->email]);
            if ($ticket['commentId'] !== null) {
                $request->session()->push(self::OPENED_TICKETS, "{$ticket['id']}:{$ticket['commentId']}");
            }

            return ['saved' => true, ...$ticket];
        });
    }

    /**
     * Imagem ou arquivo colado na descrição do ticket, anexado ao comentário inicial. Só vale para tickets abertos pelo próprio usuário nesta sessão.
     */
    public function attachFile(Request $request, string $item, string $comment, MondayService $monday): JsonResponse
    {
        abort_unless(in_array("{$item}:{$comment}", $request->session()->get(self::OPENED_TICKETS, []), true), 403);
        $request->validate(['file' => ['required', 'file', 'max:51200']], [
            'file.required' => 'Selecione um arquivo.',
            'file.file' => 'O servidor não recebeu o arquivo; ele pode ser maior que o limite de upload configurado no PHP.',
            'file.uploaded' => 'O servidor não recebeu o arquivo; ele pode ser maior que o limite de upload configurado no PHP.',
            'file.max' => 'Cada arquivo deve ter no máximo 50 MB.',
        ]);

        return $this->attempt(function () use ($request, $monday, $item, $comment): array {
            $connection = $this->connection();
            $monday->attachFile((string) $connection->token, [(string) $connection->board_id], $item, $comment, $request->file('file'));

            return ['saved' => true];
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
