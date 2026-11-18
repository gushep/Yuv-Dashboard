<?php

namespace Tests\Feature;

use App\Mcp\Prompts\ConsultingScopePrompt;
use App\Mcp\Resources\ConsultingMethodResource;
use App\Mcp\Servers\MetricsServer;
use App\Mcp\Tools\CapacityTool;
use App\Mcp\Tools\DailyMetricsTool;
use App\Mcp\Tools\DashboardTool;
use App\Mcp\Tools\HourConsumptionTool;
use App\Mcp\Tools\MondayCardTool;
use App\Mcp\Tools\OkrsTool;
use App\Mcp\Tools\ScrumIndicatorsTool;
use App\Mcp\Tools\SprintHoursTool;
use App\Mcp\Tools\TaskHistoryTool;
use App\Models\MondayConnection;
use App\Models\User;
use App\Screen;
use App\Services\MondaySnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

class McpIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-18 12:00:00');
        Http::preventStrayRequests();
    }

    public function test_the_http_server_exposes_all_authorized_tools_in_one_page(): void
    {
        Passport::actingAs(User::factory()->mcpUser()->scrumMaster()->withScreens(...Screen::cases())->create(), ['mcp:use']);

        $response = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $response->assertOk()->assertJsonCount(26, 'result.tools');
        $names = array_column($response->json('result.tools'), 'name');
        $this->assertContains('daily-metrics', $names);
        $this->assertContains('okrs', $names);
        $this->assertContains('publish-scope', $names);
        $this->assertContains('scrum-indicators', $names);
        $this->assertArrayNotHasKey('nextCursor', $response->json('result'));
    }

    public function test_consulting_reads_are_hidden_and_refused_without_the_tasks_screen(): void
    {
        $user = User::factory()->withScreens(Screen::Dashboard)->create();

        MetricsServer::actingAs($user)->tools()->assertNotRegistered([MondayCardTool::class, TaskHistoryTool::class, OkrsTool::class, DailyMetricsTool::class]);
        MetricsServer::actingAs($user)->tool(MondayCardTool::class, ['item_id' => '55'])->assertHasErrors();
        MetricsServer::actingAs($user)->tool(TaskHistoryTool::class)->assertHasErrors();
        Http::assertNothingSent();
    }

    public function test_a_token_without_the_mcp_scope_is_denied_and_audited(): void
    {
        $user = User::factory()->mcpUser()->withScreens(Screen::Dashboard)->create();
        Passport::actingAs($user, []);

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertForbidden()->assertJsonPath('error', 'insufficient_scope');

        $this->assertDatabaseHas('mcp_audit_logs', ['user_id' => $user->id, 'method' => 'tools/list', 'status' => 403]);
    }

    public function test_a_user_without_mcp_permission_is_denied_and_audited(): void
    {
        $user = User::factory()->withScreens(Screen::Dashboard)->create();
        Passport::actingAs($user, ['mcp:use']);

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertForbidden()->assertJsonPath('error', 'access_denied');

        $this->assertDatabaseHas('mcp_audit_logs', ['user_id' => $user->id, 'status' => 403]);
    }

    public function test_the_registered_prompt_and_resource_deliver_the_consulting_method(): void
    {
        $user = User::factory()->withScreens(Screen::RawData)->create();

        MetricsServer::actingAs($user)->prompt(ConsultingScopePrompt::class, ['card' => '55'])->assertOk()->assertSee(['Card do Monday: 55', 'Checklist final de qualidade']);
        MetricsServer::actingAs($user)->resource(ConsultingMethodResource::class)->assertOk()->assertSee(['Sem levantamento técnico no código', 'Modelo de referência']);
    }

    public function test_daily_metrics_read_the_kanban_xp_and_person_hours_without_creating_a_daily(): void
    {
        $this->snapshot();
        $user = User::factory()->withScreens(Screen::DailyScrum)->create();

        MetricsServer::actingAs($user)->tool(DailyMetricsTool::class)->assertOk()
            ->assertSee(['"sprint":20', '"xp":18', '"progress":1', '"done":1', '"capacity":4800', '"points":18']);
        MetricsServer::actingAs($user)->tool(DailyMetricsTool::class, ['person' => 'bia'])->assertOk()
            ->assertSee('"people":[{"name":"Bia"')->assertDontSee('"Responsavel":"Ana"');
        MetricsServer::actingAs($user)->tool(DailyMetricsTool::class, ['person' => 'inexistente'])->assertHasErrors(['Pessoa não encontrada']);
        $this->assertDatabaseCount('daily_scrums', 0);
    }

    public function test_okrs_use_the_same_snapshot_and_reject_partial_or_future_periods(): void
    {
        $this->snapshot();
        $user = User::factory()->withScreens(Screen::Okrs)->create();

        MetricsServer::actingAs($user)->tool(OkrsTool::class)->assertOk()
            ->assertSee(['"sprints":[20]', '"productivity":{"value":50', '"backlog":{"value":20', '"xp":18']);
        MetricsServer::actingAs($user)->tool(OkrsTool::class, ['from_sprint' => 20])->assertHasErrors();
        MetricsServer::actingAs($user)->tool(OkrsTool::class, ['from_sprint' => 99, 'to_sprint' => 99])->assertHasErrors(['O período não tem sprints iniciadas.']);
    }

    public function test_capacity_consumption_and_sprint_hours_are_wired_to_the_real_server(): void
    {
        $this->snapshot();
        $user = User::factory()->withScreens(Screen::Capacity, Screen::Consumption)->create();

        MetricsServer::actingAs($user)->tools()->assertRegistered([CapacityTool::class, HourConsumptionTool::class, SprintHoursTool::class])->assertNotRegistered([DashboardTool::class, ScrumIndicatorsTool::class]);
        MetricsServer::actingAs($user)->tool(CapacityTool::class)->assertOk()
            ->assertSee(['"capacityHours":80', '"registeredHours":16']);
        MetricsServer::actingAs($user)->tool(SprintHoursTool::class)->assertOk()->assertSee('"sprint":20');
        MetricsServer::actingAs($user)->tool(HourConsumptionTool::class)->assertOk()->assertSee('"sprint":20');
    }

    private function snapshot(): void
    {
        $connection = MondayConnection::current();
        $connection->forceFill(['token' => 'token-secreto', 'board_id' => '123', 'column_map' => ['Sprint' => 'sprint'],
            'metric_settings' => ['capacityHours' => 40, 'team' => [['name' => 'Ana', 'group' => 'fullstack'], ['name' => 'Bia', 'group' => 'projects']]],
        ])->save();
        $card = fn (string $id, string $name, string $status): array => ['Id' => $id, 'Nome' => 'Card '.$id, 'Sprint' => 20, 'Responsavel' => $name, 'Status' => $status, 'Tipo' => 'Melhoria', 'Planejada' => 'Planejada', 'EstH' => 10, 'RealH' => 8];

        Cache::put(MondaySnapshotService::CACHE_KEY, [
            'syncedAt' => now()->toIso8601String(),
            'rows' => [$card('1', 'Ana', 'Implementado'), $card('2', 'Bia', 'Em progresso')],
            'sprints' => [['number' => 20, 'name' => 'Sprint 20', 'start' => '2026-11-16', 'end' => '2026-11-27']],
        ], 3600);
    }
}
