<?php

namespace Tests\Feature;

use App\Models\MondayConnection;
use App\Models\User;
use App\Screen;
use App\Services\MondaySnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MondaySyncCacheTest extends TestCase
{
    use RefreshDatabase;

    /** Nome da tarefa que o Monday devolve em cada consulta ao quadro. */
    private string $taskName = 'Tarefa antiga';

    /** Nome do item que o Monday devolve em cada consulta ao quadro do Roadmap. */
    private string $roadmapItemName = 'Demanda antiga';

    /** @var array{tasks: int, roadmap: int} */
    private array $boardRequests = ['tasks' => 0, 'roadmap' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        $connection = MondayConnection::current();
        $connection->board_id = '123';
        $connection->roadmap_board_id = '999';
        $connection->token = 'token-secreto';
        $connection->column_map = ['Sprint' => 'sprint'];
        $connection->refresh_minutes = 5;
        $connection->save();
    }

    public function test_screen_opens_with_the_saved_snapshot_and_a_stale_one_is_refreshed_after_the_response(): void
    {
        $this->fakeMonday();
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/sync', ['force' => true])->assertOk();
        $this->travel(6)->minutes();
        $this->taskName = 'Tarefa nova';

        $this->postJson('/api/sync')->assertOk()
            ->assertJsonPath('rows.0.Nome', 'Tarefa antiga')
            ->assertJsonPath('refreshing', true);

        $this->assertSame(2, $this->boardRequests['tasks']);
        $this->postJson('/api/sync')->assertOk()
            ->assertJsonPath('rows.0.Nome', 'Tarefa nova')
            ->assertJsonPath('refreshing', false);
    }

    public function test_snapshot_within_the_refresh_interval_is_served_without_calling_monday(): void
    {
        $this->fakeMonday();
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/sync', ['force' => true])->assertOk();
        $this->travel(4)->minutes();

        $this->postJson('/api/sync')->assertOk()->assertJsonPath('refreshing', false);

        $this->assertSame(1, $this->boardRequests['tasks']);
    }

    public function test_without_a_saved_snapshot_the_screen_waits_for_monday_once(): void
    {
        $this->fakeMonday();

        $this->actingAs(User::factory()->create())->postJson('/api/sync')->assertOk()
            ->assertJsonPath('rows.0.Nome', 'Tarefa antiga')
            ->assertJsonPath('refreshing', false);

        $this->assertSame(1, $this->boardRequests['tasks']);
    }

    public function test_sync_sends_the_current_status_of_each_card(): void
    {
        $connection = MondayConnection::current();
        $connection->column_map = ['Sprint' => 'sprint', 'Status' => 'status'];
        $connection->save();
        $this->fakeMonday();

        $this->actingAs(User::factory()->create())->postJson('/api/sync')->assertOk()
            ->assertJsonPath('liveStatus', ['101' => 'Implementado']);
    }

    public function test_stale_snapshot_is_not_fetched_again_while_another_fetch_is_running(): void
    {
        $this->fakeMonday();
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/sync', ['force' => true])->assertOk();
        $this->travel(6)->minutes();
        $runningFetch = Cache::lock(MondaySnapshotService::CACHE_KEY.'-lock', 120);
        $runningFetch->get();

        $this->postJson('/api/sync')->assertOk()
            ->assertJsonPath('rows.0.Nome', 'Tarefa antiga')
            ->assertJsonPath('refreshing', true);

        $this->assertSame(1, $this->boardRequests['tasks']);
        $runningFetch->release();
    }

    public function test_stale_roadmap_board_is_served_at_once_and_refreshed_after_the_response(): void
    {
        $this->fakeMonday();
        $this->actingAs(User::factory()->withScreens(Screen::RawData)->create());
        $this->getJson('/api/board-tabs/roadmap')->assertOk()->assertJsonPath('rows.0.Nome', 'Demanda antiga');
        $this->travel(6)->minutes();
        $this->roadmapItemName = 'Demanda nova';

        $this->getJson('/api/board-tabs/roadmap')->assertOk()
            ->assertJsonPath('rows.0.Nome', 'Demanda antiga')
            ->assertJsonPath('refreshing', true);

        $this->getJson('/api/board-tabs/roadmap')->assertOk()
            ->assertJsonPath('rows.0.Nome', 'Demanda nova')
            ->assertJsonPath('refreshing', false);
        $this->assertSame(2, $this->boardRequests['roadmap']);
    }

    public function test_scheduled_refresh_also_updates_the_roadmap_board(): void
    {
        $this->fakeMonday();

        $this->artisan('monday:refresh')->assertSuccessful();

        $this->assertSame(1, $this->boardRequests['roadmap']);
        $this->actingAs(User::factory()->withScreens(Screen::RawData)->create())
            ->getJson('/api/board-tabs/roadmap')->assertOk()
            ->assertJsonPath('rows.0.Nome', 'Demanda antiga')
            ->assertJsonPath('refreshing', false);
        $this->assertSame(1, $this->boardRequests['roadmap']);
    }

    /**
     * Monday falso: o quadro de tarefas, o do Roadmap e o log de atividades (vazio). As atualizações depois da resposta
     * rodam na hora, para o teste ver o resultado.
     */
    private function fakeMonday(): void
    {
        $this->withoutDefer();
        Http::preventStrayRequests();
        Http::fake(['api.monday.com/v2' => function (Request $request) {
            if (str_contains($request['query'], 'activity_logs')) {
                return Http::response(['data' => ['boards' => [['activity_logs' => []]]]]);
            }
            if (($request['variables']['board'] ?? null) === ['999']) {
                $this->boardRequests['roadmap']++;

                return Http::response(['data' => ['boards' => [[
                    'name' => 'Funil de Demandas', 'columns' => [],
                    'items_page' => ['cursor' => null, 'items' => [['id' => '501', 'name' => $this->roadmapItemName, 'url' => '', 'created_at' => null, 'group' => ['title' => 'Backlog Forms'], 'column_values' => []]]],
                ]]]]);
            }
            $this->boardRequests['tasks']++;

            return Http::response(['data' => ['boards' => [[
                'name' => 'Tarefas', 'columns' => [],
                'items_page' => ['cursor' => null, 'items' => [['id' => '101', 'name' => $this->taskName, 'column_values' => [['id' => 'sprint', 'text' => 'Sprint 17'], ['id' => 'status', 'text' => 'Implementado']]]]],
            ]]]]);
        }]);
    }
}
