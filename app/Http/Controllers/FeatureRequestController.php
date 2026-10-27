<?php

namespace App\Http\Controllers;

use App\Models\MondayConnection;
use App\Services\FeatureRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Botão Novo Ticket, opção Melhoria / Feature: abre demandas no Funil de Demandas (quadro do Roadmap).
 */
class FeatureRequestController extends Controller
{
    /** Demandas abertas nesta sessão que ainda podem receber as evidências. */
    private const OPENED_REQUESTS = 'opened-feature-requests';

    public function options(Request $request, FeatureRequestService $requests): JsonResponse
    {
        return $this->attempt(fn (): array => [
            ...$requests->options($this->connection()),
            'requester' => $request->user()->name,
            'maxUploadBytes' => (int) min(UploadedFile::getMaxFilesize(), 50 * 1024 * 1024),
        ]);
    }

    public function store(Request $request, FeatureRequestService $requests): JsonResponse
    {
        $long = ['required', 'string', 'max:2000'];
        $optionalLong = ['nullable', 'string', 'max:2000'];
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'requester' => ['required', 'string', 'max:255'],
            'origin' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:255'],
            'module' => ['required', 'string', 'max:255'],
            'description' => $long,
            'affected' => ['required', 'array', 'min:1'],
            'affected.*' => ['string', 'max:255'],
            'solution' => $optionalLong,
            'requirements' => $optionalLong,
            'motivation' => ['required', 'string', 'max:255'],
            'mrr' => ['nullable', 'string', 'max:255'],
            'severity' => ['required', 'string', 'max:255'],
        ], [
            'title.required' => 'Informe o título.',
            'title.max' => 'O título deve ter no máximo 255 caracteres.',
            'requester.required' => 'Informe o nome do solicitante.',
            'origin.required' => 'Selecione a origem.',
            'type.required' => 'Selecione o tipo.',
            'module.required' => 'Selecione o módulo.',
            'description.required' => 'Descreva a demanda.',
            'affected.required' => 'Selecione quem é afetado.',
            'motivation.required' => 'Selecione a motivação para o negócio.',
            'severity.required' => 'Selecione a severidade.',
            'description.max' => 'A descrição deve ter no máximo 2.000 caracteres.',
            'solution.max' => 'A solução proposta deve ter no máximo 2.000 caracteres.',
            'requirements.max' => 'Os requisitos devem ter no máximo 2.000 caracteres.',
        ]);

        return $this->attempt(function () use ($requests, $data, $request): array {
            $trimmed = array_map(fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, $data);
            $created = $requests->create($this->connection(), [
                ...$trimmed,
                'solution' => $trimmed['solution'] ?? '', 'requirements' => $trimmed['requirements'] ?? '', 'mrr' => $trimmed['mrr'] ?? '',
                'affected' => array_values(array_unique($data['affected'])),
            ]);
            Log::info('Demanda aberta pelo dashboard.', ['item' => $created['id'], 'user' => $request->user()->email]);
            $request->session()->push(self::OPENED_REQUESTS, $created['id']);

            return ['saved' => true, ...$created];
        });
    }

    /**
     * Evidência anexada na coluna Evidências. Só vale para demandas abertas pelo próprio usuário nesta sessão.
     */
    public function attachFile(Request $request, string $item, FeatureRequestService $requests): JsonResponse
    {
        abort_unless(in_array($item, $request->session()->get(self::OPENED_REQUESTS, []), true), 403);
        $request->validate(['file' => ['required', 'file', 'max:51200']], [
            'file.required' => 'Selecione um arquivo.',
            'file.file' => 'O servidor não recebeu o arquivo; ele pode ser maior que o limite de upload configurado no PHP.',
            'file.uploaded' => 'O servidor não recebeu o arquivo; ele pode ser maior que o limite de upload configurado no PHP.',
            'file.max' => 'Cada arquivo deve ter no máximo 50 MB.',
        ]);

        return $this->attempt(function () use ($request, $requests, $item): array {
            $requests->attachEvidence($this->connection(), $item, $request->file('file'));

            return ['saved' => true];
        });
    }

    private function connection(): MondayConnection
    {
        $connection = MondayConnection::current();
        if (blank($connection->token)) {
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
