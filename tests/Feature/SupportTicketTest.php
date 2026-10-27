<?php

namespace Tests\Feature;

use App\Models\MondayConnection;
use App\Models\User;
use App\Screen;
use App\Services\MondaySnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = MondayConnection::current();
        $connection->board_id = '123';
        $connection->token = 'token-secreto';
        $connection->column_map = ['Sprint' => 'task_sprint', 'Status' => 'task_status', 'Tipo' => 'task_type', 'Prioridade' => 'task_priority'];
        $connection->save();
        // Sprint 17 de 28/09 a 09/10; sprint 18 de 13/10 a 24/10 (há um fim de semana prolongado entre elas).
        Http::preventStrayRequests();
    }

    public function test_opening_tickets_requires_the_permission_and_one_of_the_screens_with_the_button(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::Firefighter)->create());
        $this->getJson('/api/tickets/options')->assertForbidden();
        $this->postJson('/api/tickets', $this->ticket())->assertForbidden();

        $this->actingAs(User::factory()->withScreens(Screen::Dashboard)->canCreateTickets()->create());
        $this->postJson('/api/tickets', $this->ticket())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_button_is_shown_only_to_who_can_open_tickets(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::Firefighter, Screen::RawData)->create())
            ->get('/bombeiro')->assertOk()->assertDontSee('NewTicket.open()')->assertDontSee('NewTicket.toggleChat()')->assertDontSee('new-ticket.js');
        $this->actingAs(User::factory()->withScreens(Screen::Firefighter)->canCreateTickets()->create())
            ->get('/bombeiro')->assertOk()->assertSee('NewTicket.open()')->assertSee('NewTicket.toggleChat()')->assertSee('new-ticket.js');
    }

    public function test_floating_chat_button_is_on_every_screen_for_who_can_open_tickets(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::Firefighter, Screen::Dashboard)->canCreateTickets()->create(['name' => 'Davi Suporte']))
            ->get('/dashboard')->assertOk()->assertSee('NewTicket.toggleChat()')->assertSee('data-user="Davi Suporte"', false);
    }

    public function test_options_list_the_board_priorities_and_the_current_sprint(): void
    {
        $this->today('2026-10-15');
        Http::fake(['api.monday.com/v2' => Http::response($this->columnsResponse())]);

        $this->actingAs(User::factory()->withScreens(Screen::Firefighter)->canCreateTickets()->create())
            ->getJson('/api/tickets/options')->assertOk()
            ->assertJsonPath('priorities', ['Crítica', 'Alta', 'Baixa', 'Média'])
            ->assertJsonPath('sprint', ['number' => 18, 'name' => 'Sprint 18']);
    }

    public function test_between_sprints_the_ticket_goes_to_the_next_one(): void
    {
        $this->today('2026-10-11');
        Http::fake(['api.monday.com/v2' => Http::response($this->columnsResponse())]);

        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canCreateTickets()->create())
            ->getJson('/api/tickets/options')->assertOk()->assertJsonPath('sprint.number', 18);
    }

    public function test_ticket_is_created_as_support_in_backlog_in_the_current_sprint_with_its_author(): void
    {
        $this->today('2026-10-01');
        Http::fakeSequence('api.monday.com/v2')
            ->push($this->columnsResponse())
            ->push(['data' => ['create_item' => ['id' => '901', 'url' => 'https://monday.com/boards/123/pulses/901']]])
            ->push(['data' => ['items' => [['id' => '901', 'board' => ['id' => '123']]]]])
            ->push(['data' => ['create_update' => ['id' => '9901']]]);
        $user = User::factory()->withScreens(Screen::Firefighter)->canCreateTickets()->create(['name' => 'Davi Suporte']);

        $this->actingAs($user)->postJson('/api/tickets', [...$this->ticket(), 'client' => ' Transportadora Alfa '])->assertOk()
            ->assertJsonPath('id', '901')
            ->assertJsonPath('url', 'https://monday.com/boards/123/pulses/901')
            ->assertJsonPath('sprint.number', 17)
            ->assertJsonPath('commentId', '9901');

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'create_item')
            && $request['variables']['name'] === 'Erro ao emitir relatório'
            && json_decode($request['variables']['values'], true) === [
                'task_type' => ['label' => 'Suporte'],
                'task_status' => ['label' => 'Backlog'],
                'task_priority' => ['label' => 'Alta'],
                'task_sprint' => ['item_ids' => [5017]],
                'text_desc' => 'Cliente X não consegue emitir o relatório mensal.',
                'text_client' => 'Transportadora Alfa',
            ]);
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'create_update')
            && str_contains($request['variables']['body'], 'Cliente: Transportadora Alfa')
            && str_contains($request['variables']['body'], 'Enviado por Davi Suporte'));
        $this->assertNull(Cache::get(MondaySnapshotService::CACHE_KEY));
    }

    public function test_description_images_are_attached_only_to_the_comment_of_a_ticket_opened_in_this_session(): void
    {
        $this->today('2026-10-01');
        Http::fakeSequence('api.monday.com/v2/file')->push(['data' => ['add_file_to_update' => ['id' => '7001']]]);
        Http::fakeSequence('api.monday.com/v2')
            ->push($this->columnsResponse())
            ->push(['data' => ['create_item' => ['id' => '901', 'url' => '']]])
            ->push(['data' => ['items' => [['id' => '901', 'board' => ['id' => '123']]]]])
            ->push(['data' => ['create_update' => ['id' => '9901']]])
            ->push(['data' => ['items' => [['id' => '901', 'board' => ['id' => '123']]], 'updates' => [['id' => '9901', 'item_id' => '901']]]]);
        $this->actingAs(User::factory()->withScreens(Screen::Firefighter)->canCreateTickets()->create());
        $upload = fn (string $item, string $comment) => $this->post("/api/tickets/{$item}/comments/{$comment}/files",
            ['file' => UploadedFile::fake()->image('print-erro.png')], ['Accept' => 'application/json']);

        $upload('901', '9901')->assertForbidden();
        $this->postJson('/api/tickets', $this->ticket())->assertOk()->assertJsonPath('commentId', '9901');
        $upload('555', '9901')->assertForbidden();
        $upload('901', '9901')->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.monday.com/v2/file'
            && $request->hasFile('variables[file]', null, 'print-erro.png')
            && str_contains((string) collect($request->data())->firstWhere('name', 'query')['contents'], 'add_file_to_update(update_id: 9901'));
    }

    public function test_board_without_client_column_is_reported_before_creating(): void
    {
        $this->today('2026-10-01');
        Http::fake(['api.monday.com/v2' => Http::response($this->columnsResponse(withClient: false))]);
        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canCreateTickets()->create());

        $this->getJson('/api/tickets/options')->assertUnprocessable()->assertJsonPath('error', 'O quadro não tem a coluna de texto Cliente.');
        $this->postJson('/api/tickets', $this->ticket())->assertUnprocessable()->assertJsonPath('error', 'O quadro não tem a coluna de texto Cliente.');
        Http::assertSentCount(2);
    }

    public function test_title_description_and_priority_are_required(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canCreateTickets()->create())
            ->postJson('/api/tickets', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'title' => 'Informe o título do ticket.',
                'client' => 'Informe o cliente.',
                'description' => 'Informe a descrição do ticket.',
                'priority' => 'Selecione a prioridade.',
            ]);
        Http::assertNothingSent();
    }

    public function test_unknown_priority_or_board_without_support_type_is_refused_before_creating(): void
    {
        $this->today('2026-10-01');
        $user = User::factory()->withScreens(Screen::RawData)->canCreateTickets()->create();
        Http::fakeSequence('api.monday.com/v2')
            ->push($this->columnsResponse())
            ->push($this->columnsResponse(typeLabels: ['0' => 'Bug', '1' => 'Melhoria']));

        $this->actingAs($user)->postJson('/api/tickets', [...$this->ticket(), 'priority' => 'Urgentíssima'])
            ->assertUnprocessable()->assertJsonPath('error', 'Prioridade inválida para este quadro.');
        $this->postJson('/api/tickets', $this->ticket())
            ->assertUnprocessable()->assertJsonPath('error', 'A coluna Tipo não tem o rótulo "Suporte".');
        Http::assertSentCount(2);
    }

    /**
     * Fixa o dia e deixa no cache um snapshot recente com o cronograma:
     * sprint 17 de 28/09 a 09/10 e sprint 18 de 13/10 a 24/10 (com um fim de semana prolongado entre elas).
     */
    private function today(string $date): void
    {
        $this->travelTo(CarbonImmutable::parse("{$date} 10:00", 'America/Sao_Paulo'));
        Cache::put(MondaySnapshotService::CACHE_KEY, [
            'rows' => [], 'syncedAt' => now()->toIso8601String(),
            'sprints' => [
                ['number' => 17, 'name' => 'Sprint 17', 'start' => '2026-09-28', 'end' => '2026-10-09', 'source' => ''],
                ['number' => 18, 'name' => 'Sprint 18', 'start' => '2026-10-13', 'end' => '2026-10-24', 'source' => ''],
            ],
            'sprintItems' => ['5017' => 17, '5018' => 18],
        ], 3600);
    }

    /**
     * @return array{title: string, client: string, description: string, priority: string}
     */
    private function ticket(): array
    {
        return ['title' => 'Erro ao emitir relatório', 'client' => 'Transportadora Alfa', 'description' => 'Cliente X não consegue emitir o relatório mensal.', 'priority' => 'Alta'];
    }

    /**
     * @param  array<string, string>  $typeLabels
     * @return array<string, mixed>
     */
    private function columnsResponse(array $typeLabels = ['0' => 'Bug', '9' => 'Suporte'], bool $withClient = true): array
    {
        $status = fn (array $labels): string => json_encode(['labels' => $labels], JSON_UNESCAPED_UNICODE);

        return ['data' => ['boards' => [['columns' => [
            ['id' => 'name', 'title' => 'Nome', 'type' => 'name', 'settings_str' => '{}'],
            ['id' => 'task_status', 'title' => 'Status', 'type' => 'status', 'settings_str' => $status(['0' => 'Em progresso', '5' => 'Backlog'])],
            ['id' => 'task_priority', 'title' => 'Prioridade', 'type' => 'status', 'settings_str' => $status(['0' => 'Crítica', '2' => 'Alta', '5' => 'Baixa', '7' => 'Média', '12' => 'Baixa'])],
            ['id' => 'task_type', 'title' => 'Tipo', 'type' => 'status', 'settings_str' => $status($typeLabels)],
            ['id' => 'task_sprint', 'title' => 'Sprint', 'type' => 'board_relation', 'settings_str' => '{"boardIds":[456]}'],
            ['id' => 'text_desc', 'title' => 'Descrição', 'type' => 'text', 'settings_str' => '{}'],
            ...($withClient ? [['id' => 'text_client', 'title' => 'Cliente', 'type' => 'text', 'settings_str' => '{}']] : []),
        ]]]]];
    }
}
