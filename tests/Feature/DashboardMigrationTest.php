<?php

namespace Tests\Feature;

use App\Models\MondayConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_and_api_require_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->getJson('/api/config')->assertUnauthorized();
        $this->postJson('/api/sync', [])->assertUnauthorized();
    }

    public function test_authenticated_dashboard_keeps_existing_panels_and_csrf(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('Painel do Bombeiro')
            ->assertSee('Painel da Sprint')
            ->assertSee('Apresentação dos Painéis')
            ->assertSee('csrf-token');
    }

    public function test_user_can_sign_in_and_sign_out(): void
    {
        $user = User::factory()->create(['email' => 'usuario@yuv.test', 'password' => 'senha-segura-123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'senha-segura-123'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_token_is_encrypted_and_capacity_is_persisted(): void
    {
        $this->actingAs(User::factory()->create());
        $connection = MondayConnection::current();
        $connection->token = 'token-de-teste';
        $connection->save();

        $this->assertNotSame('token-de-teste', $connection->getRawOriginal('token'));
        $this->getJson('/api/config')->assertOk()->assertJsonPath('hasToken', true)->assertDontSee('token-de-teste');
        $this->postJson('/api/capacity', ['sprint' => '17', 'developer' => 'Gustavo', 'minutes' => 4800])
            ->assertOk()->assertJsonPath('overrides.17|Gustavo', 4800);
        $this->getJson('/api/capacity')->assertJsonPath('overrides.17|Gustavo', 4800);
    }

    public function test_monday_sync_preserves_hours_sprints_and_unassigned_support(): void
    {
        $this->actingAs(User::factory()->create());
        $connection = MondayConnection::current();
        $connection->board_id = '123';
        $connection->token = 'token-de-teste';
        $connection->column_map = [
            'Sprint' => 'sprint', 'Tipo' => 'tipo', 'EstH' => 'est', 'RealH' => 'real',
        ];
        $connection->save();
        Http::fake(['api.monday.com/v2' => Http::response(['data' => [
            'boards' => [[
                'name' => 'Tarefas', 'columns' => [],
                'items_page' => ['cursor' => null, 'items' => [
                    [
                        'id' => '101', 'url' => 'https://monday.com/boards/123/pulses/101', 'name' => 'Tarefa A',
                        'column_values' => [
                            ['id' => 'sprint', 'type' => 'board_relation', 'display_value' => 'Sprint 17, Sprint 18', 'text' => ''],
                            ['id' => 'tipo', 'type' => 'status', 'text' => 'Melhoria'],
                            ['id' => 'est', 'type' => 'text', 'text' => '1h30'],
                            ['id' => 'real', 'type' => 'time_tracking', 'duration' => 5400, 'text' => ''],
                        ],
                    ],
                    [
                        'id' => '102', 'url' => 'https://monday.com/boards/123/pulses/102', 'name' => 'Suporte sem sprint',
                        'column_values' => [
                            ['id' => 'sprint', 'type' => 'board_relation', 'display_value' => '', 'text' => ''],
                            ['id' => 'tipo', 'type' => 'status', 'text' => 'Suporte'],
                        ],
                    ],
                ]],
            ]],
        ]])]);

        $this->postJson('/api/sync', ['force' => true])
            ->assertOk()
            ->assertJsonPath('fetched', 2)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('rows.0.Sprint', 17)
            ->assertJsonPath('rows.1.Sprint', 18)
            ->assertJsonPath('rows.0.EstH', 1.5)
            ->assertJsonPath('rows.0.RealH', 1.5)
            ->assertJsonPath('unassignedSupport.0.Id', '102');
        $this->postJson('/api/sync', ['sprintPanel' => true])->assertOk();
        $this->assertCount(1, Http::recorded(fn ($request): bool => ! str_contains($request['query'], 'activity_logs')));
    }
}
