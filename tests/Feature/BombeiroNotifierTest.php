<?php

namespace Tests\Feature;

use App\Models\MondayActivityEvent;
use App\Models\MondayConnection;
use App\Services\BombeiroNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class BombeiroNotifierTest extends TestCase
{
    use RefreshDatabase;

    private const TICKET = ['title' => 'Erro ao emitir relatório', 'description' => 'Cliente X não consegue emitir.', 'priority' => 'Alta', 'client' => 'Transportadora Alfa'];

    private const CREATED = ['id' => '901', 'url' => 'https://monday.com/boards/123/pulses/901', 'sprint' => ['number' => 17, 'name' => 'Sprint 17']];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
    }

    public function test_nothing_is_sent_while_the_notifier_is_not_configured(): void
    {
        config(['services.notifier.url' => 'http://notifier.test', 'services.notifier.destination' => null]);
        Http::fake();

        app(BombeiroNotifier::class)->ticketCreated(self::TICKET, self::CREATED, 'Davi Suporte');

        Http::assertNothingSent();
    }

    public function test_teams_failure_is_retried(): void
    {
        config(['services.notifier.url' => 'http://notifier.test/', 'services.notifier.destination' => 'bombeiro']);
        Http::fakeSequence('notifier.test/*')->push(['slug' => 'BAD_GATEWAY'], 502)->push(['key' => 'monday.card.901'], 201);

        app(BombeiroNotifier::class)->ticketCreated(self::TICKET, self::CREATED, 'Davi Suporte');

        Http::assertSentCount(2);
    }

    public function test_status_change_of_a_support_card_updates_its_card_and_replies_who_changed_it(): void
    {
        config(['services.notifier.url' => 'http://notifier.test', 'services.notifier.destination' => 'bombeiro']);
        $connection = $this->connection();
        $change = MondayActivityEvent::factory()->create([
            'item_id' => '901', 'user_id' => '7', 'occurred_at' => now()->subMinutes(5),
            'previous_value' => ['label' => ['text' => 'Backlog']], 'value' => ['label' => ['text' => 'Implementado']],
        ]);
        $otherCard = MondayActivityEvent::factory()->create(['item_id' => '902', 'occurred_at' => now()->subMinutes(5)]);
        $old = MondayActivityEvent::factory()->create(['item_id' => '901', 'occurred_at' => now()->subHours(3)]);
        Http::fake([
            'api.monday.com/v2' => Http::response(['data' => ['users' => [['id' => '7', 'name' => 'Ana Souza']]]]),
            'notifier.test/*' => Http::response(['key' => 'monday.card.901']),
        ]);

        $sent = app(BombeiroNotifier::class)->statusChanges($connection, $this->snapshot());

        $this->assertSame(1, $sent);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://notifier.test/v1/messages/bombeiro/monday.card.901'
            && $request['title'] === 'Ticket: Erro ao emitir relatório'
            && $request['severity'] === 'ok'
            && $request['reply'] === 'Status: Backlog → Implementado · por Ana Souza'
            && ! isset($request['mention'])
            && $request['facts'] === [
                ['name' => 'Cliente', 'value' => 'Transportadora Alfa'],
                ['name' => 'Prioridade', 'value' => 'Alta'],
                ['name' => 'Status', 'value' => 'Implementado'],
                ['name' => 'Sprint', 'value' => 'Sprint 17'],
            ]);
        Http::assertSentCount(2);
        $this->assertNotNull($change->fresh()->notified_at);
        $this->assertNotNull($otherCard->fresh()->notified_at);
        $this->assertNotNull($old->fresh()->notified_at);
    }

    public function test_status_change_waits_for_the_next_round_while_the_notifier_is_down(): void
    {
        config(['services.notifier.url' => 'http://notifier.test', 'services.notifier.destination' => 'bombeiro']);
        $connection = $this->connection();
        $change = MondayActivityEvent::factory()->create(['item_id' => '901', 'user_id' => null, 'occurred_at' => now()->subMinutes(5)]);
        Http::fakeSequence('notifier.test/*')->push([], 502)->push([], 502)->push([], 502)->push(['key' => 'monday.card.901']);

        $this->assertSame(0, app(BombeiroNotifier::class)->statusChanges($connection, $this->snapshot()));
        $this->assertNull($change->fresh()->notified_at);

        $this->assertSame(1, app(BombeiroNotifier::class)->statusChanges($connection, $this->snapshot()));
        $this->assertNotNull($change->fresh()->notified_at);
    }

    public function test_a_wrong_destination_is_logged_without_retrying(): void
    {
        config(['services.notifier.url' => 'http://notifier.test', 'services.notifier.destination' => 'bombero']);
        Http::fake(['notifier.test/*' => Http::response(['slug' => 'NOT_FOUND'], 404)]);
        Log::spy();

        app(BombeiroNotifier::class)->ticketCreated(self::TICKET, self::CREATED, 'Davi Suporte');

        Http::assertSentCount(1);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['item'] === '901');
    }

    private function connection(): MondayConnection
    {
        $connection = MondayConnection::current();
        $connection->board_id = '123';
        $connection->token = 'token-secreto';
        $connection->column_map = ['Sprint' => 'sprint', 'Status' => 'status'];
        $connection->save();

        return $connection;
    }

    /**
     * Card 901 é de Suporte; 902 é tarefa de sprint.
     *
     * @return array{rows: list<array<string, mixed>>, unassignedSupport: list<array<string, mixed>>}
     */
    private function snapshot(): array
    {
        return [
            'rows' => [
                ['Id' => '901', 'Url' => 'https://monday.com/boards/123/pulses/901', 'Nome' => 'Erro ao emitir relatório', 'Tipo' => 'Suporte', 'Status' => 'Implementado',
                    'Prioridade' => 'Alta', 'Cliente' => 'Transportadora Alfa', 'Sprint' => 17, 'Responsavel' => '', 'Descricao' => ''],
                ['Id' => '902', 'Url' => '', 'Nome' => 'Nova tela', 'Tipo' => 'Feature', 'Status' => 'Implementado', 'Sprint' => 17],
            ],
            'unassignedSupport' => [],
        ];
    }
}
