<?php

namespace Tests\Feature;

use App\Models\MondayConnection;
use App\Models\User;
use App\Screen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RoadmapTest extends TestCase
{
    use RefreshDatabase;

    private const FUNNEL_STATUS = '{"labels":{"0":"Aguardando revisão","1":"Adicionado ao Backlog","2":"Implementado"},"labels_colors":{"0":{"color":"#fdab3d"},"1":{"color":"#579bfc"},"2":{"color":"#00c875"}}}';

    protected function setUp(): void
    {
        parent::setUp();
        $connection = MondayConnection::current();
        $connection->board_id = '123';
        $connection->token = 'token-secreto';
        $connection->column_map = ['Sprint' => 'sprint', 'Status' => 'task_status'];
        $connection->save();
        Http::preventStrayRequests();
    }

    public function test_roadmap_tab_reports_when_the_board_is_not_configured(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::RawData)->create())
            ->getJson('/api/roadmap')->assertOk()->assertExactJson(['configured' => false]);
        Http::assertNothingSent();
    }

    public function test_roadmap_requires_the_tasks_screen_and_its_board_only_settings_can_change(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::Dashboard)->create())->getJson('/api/roadmap')->assertForbidden();
        $this->actingAs(User::factory()->withScreens(Screen::RawData)->create())
            ->postJson('/api/roadmap-board', ['roadmapBoardId' => '999'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_settings_save_the_roadmap_board_after_checking_it_can_be_read(): void
    {
        Http::fake(['api.monday.com/v2' => Http::response(['data' => ['boards' => [['id' => '999', 'name' => 'Funil de Demandas', 'columns' => []]]]])]);
        $this->actingAs(User::factory()->withScreens(Screen::Settings)->create());

        $this->postJson('/api/roadmap-board', ['roadmapBoardId' => 'abc'])
            ->assertUnprocessable()->assertJsonValidationErrors(['roadmapBoardId' => 'Informe o ID numérico do quadro']);
        $this->postJson('/api/roadmap-board', ['roadmapBoardId' => '999'])
            ->assertOk()->assertJsonPath('boardName', 'Funil de Demandas');

        $this->assertSame('999', MondayConnection::current()->roadmap_board_id);
        $this->getJson('/api/config')->assertJsonPath('roadmapBoardId', '999');
    }

    public function test_roadmap_items_come_with_columns_recognized_by_title_and_are_cached(): void
    {
        $this->configureRoadmap();
        Http::fake(['api.monday.com/v2' => Http::response(['data' => ['boards' => [[
            'name' => 'Funil de Demandas',
            'columns' => [
                ['id' => 'priority_1', 'title' => 'Impacto', 'type' => 'status', 'settings_str' => '{}'],
                ['id' => 'bug_status', 'title' => 'Status', 'type' => 'status', 'settings_str' => self::FUNNEL_STATUS],
                ['id' => 'client', 'title' => 'Cliente', 'type' => 'text', 'settings_str' => '{}'],
                ['id' => 'origin', 'title' => 'Origem', 'type' => 'status', 'settings_str' => '{}'],
                ['id' => 'module', 'title' => 'Modulo', 'type' => 'status', 'settings_str' => '{}'],
                ['id' => 'effort', 'title' => 'Esforço', 'type' => 'status', 'settings_str' => '{}'],
                ['id' => 'estimate', 'title' => 'Estimativa (h)', 'type' => 'numbers', 'settings_str' => '{}'],
            ],
            'items_page' => ['cursor' => null, 'items' => [[
                'id' => '501', 'name' => 'Dashboard de Motoristas', 'url' => 'https://monday.com/boards/999/pulses/501', 'created_at' => '2026-10-01T10:00:00Z',
                'group' => ['title' => 'Backlog Forms'],
                'column_values' => [
                    ['id' => 'priority_1', 'text' => 'Média', 'type' => 'status'], ['id' => 'bug_status', 'text' => 'Aguardando revisão', 'type' => 'status'],
                    ['id' => 'client', 'text' => ' Powertec ', 'type' => 'text'], ['id' => 'origin', 'text' => 'Produto', 'type' => 'status'],
                    ['id' => 'module', 'text' => 'Relatorios', 'type' => 'status'], ['id' => 'effort', 'text' => 'Média', 'type' => 'status'],
                    ['id' => 'estimate', 'text' => '16', 'type' => 'numbers'],
                ],
            ]]],
        ]]]])]);
        $this->actingAs(User::factory()->withScreens(Screen::RawData)->create());

        $this->getJson('/api/roadmap')->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('boardName', 'Funil de Demandas')
            ->assertJsonPath('rows.0.Nome', 'Dashboard de Motoristas')
            ->assertJsonPath('rows.0.Status', 'Aguardando revisão')
            ->assertJsonPath('rows.0.Prioridade', 'Média')
            ->assertJsonPath('rows.0.Cliente', 'Powertec')
            ->assertJsonPath('rows.0.Origem', 'Produto')
            ->assertJsonPath('rows.0.Modulo', 'Relatorios')
            ->assertJsonPath('rows.0.Esforco', 'Média')
            ->assertJsonPath('rows.0.EstH', 16)
            ->assertJsonPath('statusOptions.0', ['label' => 'Aguardando revisão', 'color' => '#fdab3d']);
        $this->getJson('/api/roadmap')->assertOk();
        Http::assertSentCount(1);
    }

    public function test_status_of_a_roadmap_item_changes_using_the_status_column_of_its_own_board(): void
    {
        $this->configureRoadmap();
        Http::fakeSequence('api.monday.com/v2')
            ->push(['data' => ['items' => [['id' => '501', 'board' => ['id' => '999', 'columns' => [
                ['id' => 'priority_1', 'title' => 'Impacto', 'type' => 'status', 'settings_str' => '{}'],
                ['id' => 'bug_status', 'title' => 'Status', 'type' => 'status', 'settings_str' => self::FUNNEL_STATUS],
            ]]]]]])
            ->push(['data' => ['change_simple_column_value' => ['id' => '501']]]);

        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canUpdateItems()->create())
            ->postJson('/api/items/501/status', ['status' => 'Adicionado ao Backlog'])->assertOk();

        Http::assertSent(fn (Request $request): bool => str_starts_with((string) $request['query'], 'mutation')
            && $request['variables'] === ['board' => '999', 'item' => '501', 'column' => 'bug_status', 'value' => 'Adicionado ao Backlog']);
    }

    public function test_items_from_boards_other_than_tasks_and_roadmap_stay_refused(): void
    {
        $this->configureRoadmap();
        Http::fake(['api.monday.com/v2' => Http::response(['data' => ['items' => [['id' => '777', 'board' => ['id' => '555', 'columns' => []]]]]])]);

        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canUpdateItems()->create())
            ->postJson('/api/items/777/status', ['status' => 'Feito'])
            ->assertUnprocessable()->assertJsonPath('error', 'Card não encontrado no quadro configurado.');
        Http::assertSentCount(1);
    }

    private function configureRoadmap(): void
    {
        $connection = MondayConnection::current();
        $connection->roadmap_board_id = '999';
        $connection->save();
    }
}
