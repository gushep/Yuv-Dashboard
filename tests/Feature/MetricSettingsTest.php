<?php

namespace Tests\Feature;

use App\Models\MondayConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetricSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_metric_settings_require_login(): void
    {
        $this->postJson('/api/metric-settings', ['capacityHours' => 80, 'excludedPeople' => []])->assertUnauthorized();
    }

    public function test_config_exposes_default_rules_when_nothing_was_saved(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/config')
            ->assertOk()
            ->assertJsonPath('metrics.capacityHours', 80)
            ->assertJsonPath('metrics.excludedPeople', []);
    }

    public function test_saving_rules_trims_and_deduplicates_people_and_returns_them_in_config(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/metric-settings', ['capacityHours' => 72.5, 'excludedPeople' => [' Ana Souza ', 'Ana Souza', '', 'Bia Lima']])
            ->assertOk()
            ->assertJsonPath('metrics.capacityHours', 72.5)
            ->assertJsonPath('metrics.excludedPeople', ['Ana Souza', 'Bia Lima']);

        $this->getJson('/api/config')->assertJsonPath('metrics.excludedPeople', ['Ana Souza', 'Bia Lima']);
        $this->assertSame(72.5, MondayConnection::current()->metricSettings()['capacityHours']);
    }

    public function test_invalid_rules_are_rejected_without_changing_saved_values(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/metric-settings', ['capacityHours' => 60, 'excludedPeople' => ['Ana Souza']])->assertOk();

        $this->postJson('/api/metric-settings', ['capacityHours' => 201, 'excludedPeople' => [str_repeat('a', 151)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['capacityHours', 'excludedPeople.0']);

        $this->assertSame(['capacityHours' => 60, 'excludedPeople' => ['Ana Souza'], 'team' => []], MondayConnection::current()->metricSettings());
    }

    public function test_saving_the_teams_deduplicates_people_by_name_and_keeps_the_previous_exclusions(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/metric-settings', ['capacityHours' => 80, 'excludedPeople' => ['QA']])->assertOk();

        $this->postJson('/api/metric-settings', ['capacityHours' => 80, 'team' => [
            ['id' => '1', 'name' => ' Ana Souza ', 'group' => 'fullstack'],
            ['id' => '1', 'name' => 'Ana Souza', 'group' => 'projects'],
            ['id' => '2', 'name' => 'Bia Lima', 'group' => 'fullstack'],
        ]])->assertOk();

        $this->getJson('/api/config')->assertJsonPath('metrics.team', [
            ['id' => '1', 'name' => 'Ana Souza', 'group' => 'projects'],
            ['id' => '2', 'name' => 'Bia Lima', 'group' => 'fullstack'],
        ])->assertJsonPath('metrics.excludedPeople', ['QA']);
    }

    public function test_a_team_other_than_full_stack_or_projects_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/metric-settings', ['capacityHours' => 80, 'team' => [['id' => '1', 'name' => 'Ana', 'group' => 'qa']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['team.0.group' => 'Escolha Full stack ou Projetos para cada pessoa classificada.']);

        $this->assertSame([], MondayConnection::current()->metricSettings()['team']);
    }

    public function test_active_monday_users_are_listed_for_the_team_classification(): void
    {
        $this->actingAs(User::factory()->create());
        $connection = MondayConnection::current();
        $connection->board_id = '123';
        $connection->token = 'token-de-teste';
        $connection->save();
        Http::fake(['api.monday.com/v2' => Http::response(['data' => ['users' => [
            ['id' => 2, 'name' => 'Bruno', 'enabled' => true],
            ['id' => 3, 'name' => 'Desativado', 'enabled' => false],
            ['id' => 1, 'name' => 'Ana', 'enabled' => true],
        ]]])]);

        $this->getJson(route('monday-users.index'))->assertOk()
            ->assertExactJson(['users' => [['id' => '1', 'name' => 'Ana'], ['id' => '2', 'name' => 'Bruno']]]);
        // Consulta sem variáveis: o Monday recusa "variables": [] com HTTP 400; precisa ir como objeto.
        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"variables":{}'));
    }
}
