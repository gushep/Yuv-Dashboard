<?php

namespace Tests\Feature;

use App\Http\Controllers\MondayController;
use App\Models\MondayConnection;
use App\Models\User;
use App\Screen;
use App\Services\MondaySnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MondayBulkEditTest extends TestCase
{
    use RefreshDatabase;

    private const PRIORITY_SETTINGS = '{"labels":{"0":"Alta","1":"Média","2":"Baixa"}}';

    private const TASK_COLUMNS = [
        ['id' => 'status', 'title' => 'Status', 'type' => 'status', 'settings_str' => '{"labels":{"0":"Backlog","1":"Feito"}}'],
        ['id' => 'prio', 'title' => 'Prioridade', 'type' => 'status', 'settings_str' => self::PRIORITY_SETTINGS],
        ['id' => 'cliente_txt', 'title' => 'Cliente', 'type' => 'text', 'settings_str' => '{}'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $connection = MondayConnection::current();
        $connection->board_id = '123';
        $connection->roadmap_board_id = '999';
        $connection->token = 'token-secreto';
        $connection->column_map = ['Sprint' => 'sprint', 'Status' => 'status', 'Prioridade' => 'prio'];
        $connection->save();
        Http::preventStrayRequests();
    }

    public function test_bulk_edit_requires_the_permission_to_update_cards_and_a_supported_field(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::RawData)->create())
            ->postJson('/api/items/bulk', ['items' => ['55'], 'field' => 'Status', 'value' => 'Feito'])->assertForbidden();
        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canUpdateItems()->create())
            ->postJson('/api/items/bulk', ['items' => ['55'], 'field' => 'Responsavel', 'value' => 'Ana'])
            ->assertUnprocessable()->assertJsonValidationErrors('field');
        Http::assertNothingSent();
    }

    public function test_priority_of_several_tasks_changes_in_a_single_mutation_and_caches_are_discarded(): void
    {
        Cache::put(MondaySnapshotService::CACHE_KEY, ['rows' => []], 3600);
        Cache::put(MondayController::BOARD_TAB_CACHE.'roadmap', ['rows' => []], 3600);
        Http::fakeSequence('api.monday.com/v2')
            ->push(['data' => ['items' => [$this->item('55', '123', self::TASK_COLUMNS), $this->item('56', '123', self::TASK_COLUMNS)]]])
            ->push(['data' => ['m0' => ['id' => '55'], 'm1' => ['id' => '56']]]);

        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canUpdateItems()->create())
            ->postJson('/api/items/bulk', ['items' => ['55', '56'], 'field' => 'Prioridade', 'value' => 'Alta'])
            ->assertOk()->assertJsonPath('updated', ['55', '56'])->assertJsonPath('failed', []);

        Http::assertSent(fn (Request $request): bool => str_starts_with((string) $request['query'], 'mutation')
            && $request['variables'] === [
                'b0' => '123', 'i0' => '55', 'c0' => 'prio', 'v0' => '{"label":"Alta"}',
                'b1' => '123', 'i1' => '56', 'c1' => 'prio', 'v1' => '{"label":"Alta"}',
            ]);
        $this->assertNull(Cache::get(MondaySnapshotService::CACHE_KEY));
        $this->assertNull(Cache::get(MondayController::BOARD_TAB_CACHE.'roadmap'));
    }

    public function test_client_of_a_roadmap_item_is_found_by_title_and_written_as_text(): void
    {
        $roadmapColumns = [['id' => 'client', 'title' => 'Cliente', 'type' => 'text', 'settings_str' => '{}']];
        Http::fakeSequence('api.monday.com/v2')
            ->push(['data' => ['items' => [$this->item('501', '999', $roadmapColumns)]]])
            ->push(['data' => ['m0' => ['id' => '501']]]);

        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canUpdateItems()->create())
            ->postJson('/api/items/bulk', ['items' => ['501'], 'field' => 'Cliente', 'value' => ' Powertec '])
            ->assertOk()->assertJsonPath('updated', ['501'])->assertJsonPath('value', 'Powertec');

        Http::assertSent(fn (Request $request): bool => ($request['variables']['c0'] ?? null) === 'client'
            && $request['variables']['v0'] === '"Powertec"');
    }

    public function test_unknown_labels_and_items_from_other_boards_are_reported_without_changing_anything(): void
    {
        Http::fake(['api.monday.com/v2' => Http::response(['data' => ['items' => [
            $this->item('55', '123', self::TASK_COLUMNS), $this->item('777', '555', []),
        ]]])]);

        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canUpdateItems()->create())
            ->postJson('/api/items/bulk', ['items' => ['55', '777', '888'], 'field' => 'Prioridade', 'value' => 'Urgente'])
            ->assertOk()
            ->assertJsonPath('updated', [])
            ->assertJsonPath('failed.55', 'Valor inválido para Prioridade neste quadro.')
            ->assertJsonPath('failed.777', 'Card não encontrado no quadro configurado.')
            ->assertJsonPath('failed.888', 'Card não encontrado no quadro configurado.');
        Http::assertSentCount(1);
    }

    public function test_a_card_refused_by_monday_does_not_block_the_others(): void
    {
        Http::fakeSequence('api.monday.com/v2')
            ->push(['data' => ['items' => [$this->item('55', '123', self::TASK_COLUMNS), $this->item('56', '123', self::TASK_COLUMNS)]]])
            ->push(['errors' => [['message' => 'Item arquivado']]])
            ->push(['data' => ['m0' => ['id' => '55']]])
            ->push(['errors' => [['message' => 'Item arquivado']]]);

        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canUpdateItems()->create())
            ->postJson('/api/items/bulk', ['items' => ['55', '56'], 'field' => 'Status', 'value' => 'Feito'])
            ->assertOk()
            ->assertJsonPath('updated', ['55'])
            ->assertJsonPath('failed', ['56' => 'Monday: Item arquivado']);
        Http::assertSentCount(4);
    }

    /**
     * @param  list<array<string, string>>  $columns
     * @return array<string, mixed>
     */
    private function item(string $id, string $boardId, array $columns): array
    {
        return ['id' => $id, 'board' => ['id' => $boardId, 'columns' => $columns]];
    }
}
