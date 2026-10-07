<?php

namespace Tests\Feature;

use App\Models\MondayConnection;
use App\Models\User;
use App\Services\MondayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class HourConsumptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_consumption_is_integrated_into_the_authenticated_dashboard(): void
    {
        $this->actingAs(User::factory()->create())->get('/')
            ->assertOk()->assertSee('Consumo de horas | Sprint e Suporte')
            ->assertSee('nav-consumption', false)->assertSee('consumption.js')
            ->assertSee('Horas sem atribuição individual');
    }

    public function test_reserve_endpoints_require_authentication(): void
    {
        $this->getJson('/api/support-reserves')->assertUnauthorized();
        $this->postJson('/api/support-reserves', ['sprint' => '17', 'minutes' => 2400])->assertUnauthorized();
    }

    public function test_collective_reserve_defaults_to_forty_hours_and_persists_per_sprint_without_changing_capacity(): void
    {
        $this->actingAs(User::factory()->create());
        $connection = MondayConnection::current();
        $connection->capacity_overrides = ['17|Ana' => 4800];
        $connection->forecast_settings = ['snapshot' => ['revisions' => ['17|101|Ana' => ['minutes' => 90]]]];
        $connection->save();

        $this->getJson('/api/support-reserves')->assertExactJson(['defaultMinutes' => 2400, 'reserves' => []]);
        $this->postJson('/api/support-reserves', ['sprint' => '17', 'minutes' => 3000])->assertJsonPath('saved', true);
        $this->postJson('/api/support-reserves', ['sprint' => '18', 'minutes' => 0])->assertJsonPath('saved', true);
        $this->getJson('/api/support-reserves')->assertJsonPath('reserves.17', 3000)->assertJsonPath('reserves.18', 0);

        $connection->refresh();
        $this->assertSame(3000, $connection->forecast_settings['supportReserves'][17]);
        $this->assertSame(0, $connection->forecast_settings['supportReserves'][18]);
        $this->assertSame(90, $connection->forecast_settings['snapshot']['revisions']['17|101|Ana']['minutes']);
        $this->assertSame(['17|Ana' => 4800], $connection->capacity_overrides);
    }

    #[TestWith(['0', 2400, 'sprint'])]
    #[TestWith(['invalid', 2400, 'sprint'])]
    #[TestWith(['17', -1, 'minutes'])]
    #[TestWith(['17', 1.5, 'minutes'])]
    #[TestWith(['17', 600001, 'minutes'])]
    public function test_invalid_reserve_returns_422_without_persisting(string $sprint, int|float $minutes, string $field): void
    {
        $this->actingAs(User::factory()->create());
        $connection = MondayConnection::current();

        $this->postJson('/api/support-reserves', compact('sprint', 'minutes'))->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertNull($connection->fresh()->forecast_settings);
    }

    public function test_import_preserves_missing_real_time_and_normalizes_hours_and_minutes(): void
    {
        $rows = app(MondayService::class)->convertItems([
            ['id' => '1', 'name' => 'Concluído', 'column_values' => [['id' => 'sprint', 'text' => 'Sprint 17'], ['id' => 'real', 'text' => '7h30']]],
            ['id' => '2', 'name' => 'Sem registro', 'column_values' => [['id' => 'sprint', 'text' => 'Sprint 17'], ['id' => 'real', 'text' => '']]],
            ['id' => '3', 'name' => 'Zero confirmado', 'column_values' => [['id' => 'sprint', 'text' => 'Sprint 17'], ['id' => 'real', 'text' => '0']]],
            ['id' => '4', 'name' => 'Duração inválida', 'column_values' => [['id' => 'sprint', 'text' => 'Sprint 17'], ['id' => 'real', 'text' => '7h90']]],
        ], ['Sprint' => 'sprint', 'RealH' => 'real'])['rows'];

        $this->assertSame(7.5, $rows[0]['RealH']);
        $this->assertNull($rows[1]['RealH']);
        $this->assertSame(0.0, $rows[2]['RealH']);
        $this->assertNull($rows[3]['RealH']);
    }
}
