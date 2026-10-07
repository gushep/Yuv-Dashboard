<?php

namespace Tests\Feature;

use App\Models\MondayConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->assertSame(['capacityHours' => 60, 'excludedPeople' => ['Ana Souza']], MondayConnection::current()->metricSettings());
    }
}
