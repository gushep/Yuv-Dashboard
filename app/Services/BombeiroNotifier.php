<?php

namespace App\Services;

use App\Models\MondayActivityEvent;
use App\Models\MondayConnection;
use App\Support\SprintRules;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Avisa o canal do Bombeiro no Teams pelo yuv-internal-notifier (wiki: "Enviar uma notificação interna").
 * Cada card do Monday é um cartão no canal, pela chave monday.card.<id>: a primeira chamada posta o cartão;
 * as seguintes editam o mesmo cartão. Sem NOTIFIER_URL e NOTIFIER_DESTINATION, não faz nada.
 */
class BombeiroNotifier
{
    /** Mudanças de status mais antigas que isso (ex.: aviso desligado por um tempo) são só marcadas, sem aviso. */
    private const MAX_AGE_HOURS = 2;

    /** Cor do cartão pela etapa do Painel do Bombeiro: Backlog pede atenção; Concluído fica verde. */
    private const SEVERITY = ['backlog' => 'warning', 'progress' => 'info', 'test' => 'info', 'done' => 'ok'];

    public function __construct(private MondayService $monday) {}

    /**
     * Ticket aberto pelo botão Novo Ticket: posta o cartão mencionando o grupo do Bombeiro.
     * Uma falha do notificador fica no log e não desfaz o ticket, que já existe no Monday.
     *
     * @param  array{title: string, description: string, priority: string, client: string}  $ticket
     * @param  array{id: string, url: string, sprint: array{number: int, name: string}}  $created
     */
    public function ticketCreated(array $ticket, array $created, string $author): void
    {
        $this->send($created['id'], [
            'title' => mb_strimwidth("Novo ticket: {$ticket['title']}", 0, 200, '…'),
            'text' => mb_strimwidth($ticket['description'], 0, 1000, '…'),
            'severity' => self::SEVERITY[SprintRules::stage(SupportTicketService::STATUS)],
            'facts' => [
                ['name' => 'Cliente', 'value' => $ticket['client']],
                ['name' => 'Prioridade', 'value' => $ticket['priority']],
                ['name' => 'Status', 'value' => SupportTicketService::STATUS],
                ['name' => 'Sprint', 'value' => $created['sprint']['name']],
                ['name' => 'Aberto por', 'value' => $author],
            ],
            ...$this->link($created['url']),
            'mention' => true,
        ]);
    }

    /**
     * Mudanças de status dos cards de Suporte, lidas do log de atividades copiado pelo monday:refresh (a cada 15 minutos),
     * inclusive as feitas direto no Monday. Atualiza o cartão do card com o novo status e a cor da etapa e responde na
     * conversa do cartão de qual status para qual e quem mudou. O Teams não notifica edições nem respostas: só o card novo menciona o grupo.
     *
     * @param  array{rows?: list<array<string, mixed>>, unassignedSupport?: list<array<string, mixed>>}  $snapshot  cards atuais do quadro
     * @return int avisos enviados
     */
    public function statusChanges(MondayConnection $connection, array $snapshot): int
    {
        $statusColumn = (string) ($connection->column_map['Status'] ?? '');
        if (! $this->enabled() || $statusColumn === '') {
            return 0;
        }
        $pending = MondayActivityEvent::query()->where('board_id', (string) $connection->board_id)->where('column_id', $statusColumn)
            ->where('event', 'update_column_value')->whereNull('notified_at');
        (clone $pending)->where('occurred_at', '<', now()->subHours(self::MAX_AGE_HOURS))->update(['notified_at' => now()]);
        $events = $pending->orderBy('occurred_at')->orderBy('id')->get();

        // Card em mais de uma sprint aparece em várias linhas com os mesmos dados; basta uma.
        $cards = collect([...$snapshot['unassignedSupport'] ?? [], ...$snapshot['rows'] ?? []])
            ->filter(fn (array $row): bool => SprintRules::normalize($row['Tipo'] ?? '') === 'suporte')->keyBy('Id');
        [$support, $others] = $events->partition(fn (MondayActivityEvent $event): bool => $cards->has($event->item_id));
        MondayActivityEvent::query()->whereKey($others->modelKeys())->update(['notified_at' => now()]);
        if ($support->isEmpty()) {
            return 0;
        }

        try {
            $names = $this->monday->userNames((string) $connection->token, $support->pluck('user_id')->filter()->unique()->values()->all());
        } catch (ConnectionException|RuntimeException) {
            $names = [];
        }

        $sent = 0;
        foreach ($support as $event) {
            $card = $cards[$event->item_id];
            $to = (string) ($event->value['label']['text'] ?? $card['Status'] ?? '');
            $from = (string) ($event->previous_value['label']['text'] ?? '') ?: 'sem status';
            $author = $names[(string) $event->user_id] ?? null;
            $handled = $this->send($event->item_id, [
                'title' => mb_strimwidth("Ticket: {$card['Nome']}", 0, 200, '…'),
                ...(filled($card['Descricao'] ?? null) ? ['text' => mb_strimwidth((string) $card['Descricao'], 0, 1000, '…')] : []),
                'severity' => self::SEVERITY[SprintRules::stage($to)],
                'facts' => $this->facts([
                    'Cliente' => $card['Cliente'] ?? '', 'Prioridade' => $card['Prioridade'] ?? '', 'Status' => $to,
                    'Sprint' => filled($card['Sprint'] ?? null) ? "Sprint {$card['Sprint']}" : '', 'Responsável' => $card['Responsavel'] ?? '',
                ]),
                ...$this->link((string) ($card['Url'] ?? '')),
                'reply' => mb_strimwidth("Status: {$from} → {$to}".($author ? " · por {$author}" : ''), 0, 2000, '…'),
            ]);
            // Notificador fora do ar: a mudança fica para a próxima rodada, mantendo a ordem dos avisos do card.
            if (! $handled) {
                break;
            }
            $event->update(['notified_at' => now()]);
            $sent++;
        }

        return $sent;
    }

    private function enabled(): bool
    {
        return filled(config('services.notifier.url')) && filled(config('services.notifier.destination'));
    }

    /**
     * Fatos do cartão; o notificador recusa fato sem valor.
     *
     * @param  array<string, string>  $values
     * @return list<array{name: string, value: string}>
     */
    private function facts(array $values): array
    {
        $facts = [];
        foreach ($values as $name => $value) {
            if (trim($value) !== '') {
                $facts[] = ['name' => $name, 'value' => trim($value)];
            }
        }

        return $facts;
    }

    /** @return array{link?: array{label: string, url: string}} */
    private function link(string $url): array
    {
        return str_starts_with($url, 'https://') ? ['link' => ['label' => 'Abrir no Monday', 'url' => $url]] : [];
    }

    /**
     * Manda o cartão inteiro (o campo que não vem some do cartão). Repetir a chamada é seguro: o notificador não duplica.
     *
     * @param  array<string, mixed>  $card
     * @return bool false quando vale tentar de novo depois (notificador ou Teams fora do ar)
     */
    private function send(string $itemId, array $card): bool
    {
        if (! $this->enabled()) {
            return true;
        }
        $url = rtrim((string) config('services.notifier.url'), '/');
        $destination = (string) config('services.notifier.destination');

        try {
            // 502 é falha do Teams: o notificador pede nova tentativa com espera crescente. 400 e 404 são erro de quem chama.
            $response = Http::timeout(30)
                ->retry([1000, 4000], when: fn (Throwable $exception): bool => $exception instanceof RequestException && $exception->response->status() === 502, throw: false)
                ->put("{$url}/v1/messages/{$destination}/monday.card.{$itemId}", $card);
        } catch (ConnectionException $exception) {
            Log::warning('Falha ao avisar o canal do Bombeiro.', ['item' => $itemId, 'error' => $exception->getMessage()]);

            return false;
        }
        if ($response->successful()) {
            return true;
        }
        Log::warning('Falha ao avisar o canal do Bombeiro.', ['item' => $itemId, 'status' => $response->status(), 'error' => $response->body()]);

        return ! $response->serverError();
    }
}
