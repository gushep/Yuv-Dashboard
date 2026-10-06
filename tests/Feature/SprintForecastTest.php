<?php

namespace Tests\Feature;

use App\Models\MondayConnection;
use App\Models\User;
use App\Services\MondayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SprintForecastTest extends TestCase
{
    use RefreshDatabase;

    public function test_forecast_is_available_without_local_editing_controls(): void
    {
        $this->actingAs(User::factory()->create())->get('/')
            ->assertOk()->assertSee('Previsibilidade da sprint')->assertSee('nav-forecast', false)
            ->assertSee('forecast.js')->assertSee('Somente visualização')
            ->assertDontSee('forecast-configure', false)->assertDontSee('forecast-config-form', false);
    }

    public function test_local_forecast_write_endpoint_is_unavailable_and_saved_settings_are_preserved(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create());
        $connection = MondayConnection::current();
        $connection->forecast_settings = ['snapshot' => ['revisions' => ['17|101|Ana' => ['minutes' => 90]]]];
        $connection->capacity_overrides = ['17|Ana' => 4800];
        $connection->save();

        $this->postJson('/api/forecast', ['action' => 'revision', 'sprint' => 17, 'developer' => 'Ana', 'item' => '101', 'minutes' => 0])->assertNotFound();
        $this->getJson('/api/capacity')->assertOk()->assertExactJson(['overrides' => ['17|Ana' => 4800]]);
        $this->assertSame(90, $connection->fresh()->forecast_settings['snapshot']['revisions']['17|101|Ana']['minutes']);
        Http::assertNothingSent();
    }

    public function test_monday_import_preserves_optional_remaining_effort_and_blocker(): void
    {
        $converted = app(MondayService::class)->convertItems([[
            'id' => '101', 'name' => 'Integração', 'column_values' => [
                ['id' => 'sprint', 'text' => 'Sprint 17'],
                ['id' => 'remaining', 'text' => '1h30'],
                ['id' => 'blocker', 'text' => 'Aguardando acesso'],
            ],
        ]], ['Sprint' => 'sprint', 'RemainingH' => 'remaining', 'Impedimento' => 'blocker']);
        $this->assertSame(1.5, $converted['rows'][0]['RemainingH']);
        $this->assertSame('Aguardando acesso', $converted['rows'][0]['Impedimento']);
    }
}
