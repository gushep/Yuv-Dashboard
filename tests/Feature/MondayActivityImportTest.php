<?php

namespace Tests\Feature;

use App\Models\MondayActivityEvent;
use App\Models\MondayConnection;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class MondayActivityImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_import_copies_all_retained_history_and_splits_batch_events_per_item(): void
    {
        $this->configureMonday();
        $this->freezeTime();
        Http::preventStrayRequests();
        Http::fake(['api.monday.com/v2' => Http::response($this->page([
            $this->log('e1', 'update_column_value', '17593344000000000', ['pulse_id' => 101, 'column_id' => 'status', 'column_title' => 'Status', 'previous_value' => ['label' => ['text' => 'Em progresso']], 'value' => ['label' => ['text' => 'Implementado']]]),
            $this->log('e2', 'batch_change_pulses_column_value', '17598300000000000', ['pulse_ids' => [102, 103], 'column_id' => 'sprint', 'column_title' => 'Sprint', 'value' => ['linkedPulseIds' => [['linkedPulseId' => 900]]]]),
            $this->log('e3', 'board_view_changed', '17598200000000000', ['board_id' => 123]),
        ]))]);

        $this->artisan('monday:import-activity')->expectsOutputToContain('4 evento(s) novo(s)')->assertSuccessful();

        $statusChange = MondayActivityEvent::query()->where('monday_id', 'e1')->sole();
        $this->assertSame('101', $statusChange->item_id);
        $this->assertSame('Em progresso', $statusChange->previous_value['label']['text']);
        $this->assertSame('Implementado', $statusChange->value['label']['text']);
        $this->assertSame('2025-10-01 16:00:00', $statusChange->occurred_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(['102', '103'], MondayActivityEvent::query()->where('monday_id', 'e2')->orderBy('item_id')->pluck('item_id')->all());
        $this->assertSame('', MondayActivityEvent::query()->where('monday_id', 'e3')->value('item_id'));
        Http::assertSent(fn (Request $request): bool => $request['variables']['from'] === null && $request['variables']['to'] === now()->toIso8601ZuluString());
    }

    public function test_next_import_starts_shortly_before_the_latest_copy_and_ignores_events_already_copied(): void
    {
        $this->configureMonday();
        MondayActivityEvent::factory()->create(['monday_id' => 'e1', 'item_id' => '101', 'occurred_at' => Carbon::parse('2025-10-01 16:00:00', 'UTC')]);
        Http::preventStrayRequests();
        Http::fake(['api.monday.com/v2' => Http::response($this->page([
            $this->log('e4', 'update_column_value', '17598340000000000', ['pulse_id' => 101, 'column_id' => 'status']),
            $this->log('e1', 'update_column_value', '17598336000000000', ['pulse_id' => 101, 'column_id' => 'status']),
        ]))]);

        $this->artisan('monday:import-activity')->expectsOutputToContain('1 evento(s) novo(s)')->assertSuccessful();

        $this->assertSame(2, MondayActivityEvent::query()->count());
        Http::assertSent(fn (Request $request): bool => $request['variables']['from'] === '2025-10-01T15:50:00Z');
    }

    public function test_import_requests_next_pages_until_a_partial_page_is_returned(): void
    {
        $this->configureMonday();
        $fullPage = array_map(fn (int $index): array => $this->log("full-{$index}", 'update_column_value', '17598336000000000', ['pulse_id' => $index]), range(1, 500));
        Http::preventStrayRequests();
        Http::fakeSequence('api.monday.com/v2')
            ->push($this->page($fullPage))
            ->push($this->page([$this->log('last', 'create_pulse', '17598000000000000', ['pulse_id' => 999])]));

        $this->artisan('monday:import-activity')->assertSuccessful();

        $this->assertSame(501, MondayActivityEvent::query()->count());
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request['variables']['page'] === 2);
    }

    public function test_import_without_monday_configuration_skips_without_requests(): void
    {
        Http::preventStrayRequests();

        $this->artisan('monday:import-activity')->expectsOutputToContain('Monday não configurado')->assertSuccessful();

        $this->assertSame(0, MondayActivityEvent::query()->count());
    }

    public function test_monday_failure_fails_the_command_and_keeps_existing_history(): void
    {
        $this->configureMonday();
        MondayActivityEvent::factory()->create();
        Http::preventStrayRequests();
        Http::fake(['api.monday.com/v2' => Http::response(['errors' => [['message' => 'Complexity budget exhausted']]])]);

        $this->artisan('monday:import-activity')->expectsOutputToContain('Complexity budget exhausted')->assertFailed();

        $this->assertSame(1, MondayActivityEvent::query()->count());
    }

    public function test_monday_refresh_updates_cached_cards_and_copies_new_activity(): void
    {
        $this->configureMonday(['Sprint' => 'sprint']);
        Http::preventStrayRequests();
        Http::fake(['api.monday.com/v2' => fn (Request $request) => Http::response(str_contains($request['query'], 'activity_logs')
            ? $this->page([$this->log('e1', 'create_pulse', '17593344000000000', ['pulse_id' => 101])])
            : $this->board())]);

        $this->artisan('monday:refresh')->expectsOutputToContain('1 registro(s) atualizados e 1 evento(s) novo(s)')->assertSuccessful();

        $this->assertSame('101', Cache::get('monday-sync-1')['rows'][0]['Id']);
        $this->assertSame(1, MondayActivityEvent::query()->count());
    }

    public function test_monday_refresh_is_scheduled_every_fifteen_minutes(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('monday:refresh')->assertSuccessful();

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command, 'monday:refresh'));
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_dashboard_sync_copies_activity_after_response_at_most_once_per_hour(): void
    {
        $this->actingAs(User::factory()->create());
        $this->configureMonday(['Sprint' => 'sprint']);
        $this->withoutDefer();
        Http::preventStrayRequests();
        Http::fake(['api.monday.com/v2' => fn (Request $request) => Http::response(str_contains($request['query'], 'activity_logs')
            ? $this->page([$this->log('e1', 'create_pulse', '17593344000000000', ['pulse_id' => 101])])
            : $this->board())]);

        $this->postJson('/api/sync', ['force' => true])->assertOk();
        $this->postJson('/api/sync', ['force' => true])->assertOk();

        $this->assertSame(1, MondayActivityEvent::query()->count());
        Http::assertSentCount(3);
        $this->travel(61)->minutes();
        $this->postJson('/api/sync', ['force' => true])->assertOk();
        Http::assertSentCount(5);
    }

    public function test_activity_copy_failure_does_not_affect_dashboard_sync_and_is_retried_on_next_sync(): void
    {
        $this->actingAs(User::factory()->create());
        $this->configureMonday(['Sprint' => 'sprint']);
        $this->withoutDefer();
        Exceptions::fake();
        Http::preventStrayRequests();
        $activityRequests = 0;
        Http::fake(['api.monday.com/v2' => function (Request $request) use (&$activityRequests) {
            if (! str_contains($request['query'], 'activity_logs')) {
                return Http::response($this->board());
            }

            return ++$activityRequests === 1
                ? Http::response(['errors' => [['message' => 'Complexity budget exhausted']]])
                : Http::response($this->page([$this->log('e1', 'create_pulse', '17593344000000000', ['pulse_id' => 101])]));
        }]);

        $this->postJson('/api/sync', ['force' => true])->assertOk()->assertJsonPath('fetched', 1);
        $this->postJson('/api/sync', ['force' => true])->assertOk();

        Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'Complexity budget exhausted'));
        $this->assertSame(1, MondayActivityEvent::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function board(): array
    {
        return ['data' => ['boards' => [[
            'name' => 'Tarefas', 'columns' => [],
            'items_page' => ['cursor' => null, 'items' => [['id' => '101', 'name' => 'Tarefa', 'column_values' => [['id' => 'sprint', 'text' => 'Sprint 17']]]]],
        ]]]];
    }

    /**
     * @param  array<string, string>  $columnMap
     */
    private function configureMonday(array $columnMap = []): void
    {
        $connection = MondayConnection::current();
        $connection->board_id = '123';
        $connection->token = 'token-secreto';
        $connection->column_map = $columnMap;
        $connection->save();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{id: string, event: string, user_id: string, created_at: string, data: string}
     */
    private function log(string $id, string $event, string $createdAt, array $data): array
    {
        return ['id' => $id, 'event' => $event, 'user_id' => '555', 'created_at' => $createdAt, 'data' => json_encode($data)];
    }

    /**
     * @param  list<array<string, mixed>>  $logs
     * @return array<string, mixed>
     */
    private function page(array $logs): array
    {
        return ['data' => ['boards' => [['activity_logs' => $logs]]]];
    }
}
