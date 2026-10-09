<?php

namespace Tests\Feature;

use App\Models\MondayConnection;
use App\Services\MondayService;
use App\Services\SprintTimeline;
use App\Support\SprintRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SprintHistoryScenario;
use Tests\TestCase;

class SprintTimelineTest extends TestCase
{
    use RefreshDatabase;
    use SprintHistoryScenario;

    public function test_closed_sprint_is_frozen_at_its_effective_end_with_moved_cards_and_without_later_cards(): void
    {
        $timeline = $this->timeline();

        $sprint16 = collect($timeline->frozenRows('2026-10-07'))->where('Sprint', 16)->keyBy('Id');

        $this->assertSame(['1', '2'], $sprint16->pluck('Id')->sort()->values()->all());
        $this->assertSame('Em progresso', $sprint16['1']['Status']);
        $this->assertSame('Em progresso', $sprint16['2']['Status']);
        $this->assertSame('2026-09-27', $sprint16['2']['AsOf']);
        $this->assertSame([16 => '2026-09-27', 17 => '2026-10-09'], $timeline->effectiveEnds());
    }

    public function test_open_sprint_rows_keep_their_current_state(): void
    {
        $rows = collect($this->timeline()->frozenRows('2026-10-07'))->where('Sprint', 17);

        $this->assertSame(['1', '4', '5', '6', '7'], $rows->pluck('Id')->sort()->values()->all());
        $this->assertNull($rows->firstWhere('Id', '1')['AsOf'] ?? null);
    }

    public function test_sprints_that_ended_before_the_history_starts_are_not_frozen(): void
    {
        $this->configureScenarioConnection();
        $this->historyEvent('1', 'status', ['label' => ['text' => 'Em progresso']], ['label' => ['text' => 'Implementado']], '2026-10-01 11:00');

        $rows = collect(SprintTimeline::fromDatabase($this->scenarioSnapshot(), MondayConnection::current())->frozenRows('2026-10-07'));

        $this->assertSame('Implementado', $rows->firstWhere('Id', '2')['Status']);
        $this->assertFalse($rows->contains(fn (array $row): bool => isset($row['AsOf'])));
    }

    public function test_deleted_cards_and_cards_no_longer_in_the_sprint_leave_the_current_membership(): void
    {
        $timeline = $this->timeline();
        $this->historyEvent('8', 'sprint', [], ['linkedPulseIds' => [['linkedPulseId' => 9017]]], '2026-10-02 09:00');
        $this->historyEvent('9', 'sprint', [], ['linkedPulseIds' => [['linkedPulseId' => 9017]]], '2026-10-02 09:00');
        $this->historyEvent('9', null, null, null, '2026-10-05 12:00', 'delete_pulse');
        $timeline = SprintTimeline::fromDatabase($this->scenarioSnapshot(), MondayConnection::current());

        $this->assertArrayHasKey('9', $timeline->membersAt(17, SprintRules::endOfDay('2026-10-02')));
        $this->assertArrayNotHasKey('9', $timeline->membersAt(17, SprintRules::endOfDay('2026-10-05')));
        $this->assertArrayHasKey('8', $timeline->membersAt(17, SprintRules::endOfDay('2026-10-06')));
        $this->assertArrayNotHasKey('8', $timeline->membersAt(17, SprintRules::endOfDay('2026-10-07')));
    }

    public function test_current_state_follows_the_synced_card_when_the_copied_history_is_behind(): void
    {
        $connection = $this->configureScenarioConnection();
        $this->seedScenarioHistory();
        $snapshot = $this->scenarioSnapshot();
        $snapshot['rows'][3]['Status'] = 'Implementado';
        $timeline = SprintTimeline::fromDatabase($snapshot, $connection);

        $this->assertSame('Em progresso', $timeline->stateAt('4', SprintRules::endOfDay('2026-10-06'))['Status']);
        $this->assertSame('Implementado', $timeline->stateAt('4', SprintRules::endOfDay('2026-10-07'))['Status']);

        $this->historyEvent('4', 'status', ['label' => ['text' => 'Implementado']], ['label' => ['text' => 'Parado']], '2026-10-07 13:00');
        $timeline = SprintTimeline::fromDatabase($snapshot, $connection);

        $this->assertSame('Parado', $timeline->stateAt('4', SprintRules::endOfDay('2026-10-07'))['Status']);
    }

    public function test_burnup_counts_scope_and_done_cards_at_the_end_of_each_working_day(): void
    {
        $points = collect($this->timeline()->burnup(17, '2026-09-28', '2026-10-09', '2026-10-07'))->keyBy('date');

        $this->assertSame(['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-05', '2026-10-06', '2026-10-07'], $points->keys()->all());
        $this->assertSame([3, 0], [$points['2026-09-28']['scope'], $points['2026-09-28']['done']]);
        $this->assertSame([3, 1], [$points['2026-10-01']['scope'], $points['2026-10-01']['done']]);
        $this->assertSame([4, 1], [$points['2026-10-02']['scope'], $points['2026-10-02']['done']]);
        $this->assertSame([5, 1], [$points['2026-10-07']['scope'], $points['2026-10-07']['done']]);
        $this->assertSame(20.0, $points['2026-10-07']['scopeHours']);
    }

    public function test_burnup_splits_each_day_into_planned_unplanned_and_support_cards(): void
    {
        $points = collect($this->timeline()->burnup(17, '2026-09-28', '2026-10-09', '2026-10-07'))->keyBy('date');

        $empty = ['scope' => 0, 'done' => 0];
        $this->assertSame(['planned' => ['scope' => 3, 'done' => 0], 'unplanned' => $empty, 'support' => $empty], $points['2026-09-28']['categories']);
        $this->assertSame(['planned' => ['scope' => 3, 'done' => 1], 'unplanned' => ['scope' => 1, 'done' => 0], 'support' => $empty], $points['2026-10-02']['categories']);
        $this->assertSame(['planned' => ['scope' => 3, 'done' => 1], 'unplanned' => ['scope' => 1, 'done' => 0], 'support' => ['scope' => 1, 'done' => 0]], $points['2026-10-07']['categories']);
    }

    public function test_scope_added_after_the_planning_days_is_listed_with_the_day_it_entered(): void
    {
        $scope = $this->timeline()->scopeChanges(17, '2026-09-28', '2026-10-09', '2026-10-07');

        $this->assertSame('2026-09-29', $scope['baselineDate']);
        $this->assertSame(3, $scope['baseline']);
        $this->assertSame(['6', '7'], collect($scope['added'])->pluck('id')->sort()->values()->all());
        $this->assertSame('Suporte', collect($scope['added'])->firstWhere('id', '6')['type']);
        $this->assertStringStartsWith('2026-10-02', collect($scope['added'])->firstWhere('id', '7')['changedAt']);
        $this->assertSame([], $scope['removed']);
    }

    public function test_status_age_counts_full_working_days_and_decodes_batch_status_changes(): void
    {
        $ages = collect($this->timeline()->statusAges(17, '2026-10-07'))->keyBy('id');

        $this->assertSame(4, $ages['4']['workingDays']);
        $this->assertFalse($ages['4']['blocked']);
        $this->assertSame('Parado', $ages['5']['status']);
        $this->assertSame(1, $ages['5']['workingDays']);
        $this->assertTrue($ages['5']['blocked']);
        $this->assertFalse($ages['6']['knownSince']);
        $this->assertTrue($ages['7']['notStarted']);
        $this->assertFalse($ages->has('1'));
    }

    public function test_daily_throughput_counts_cards_that_became_implemented_on_each_working_day(): void
    {
        $samples = $this->timeline()->dailyThroughput(60, '2026-10-07');

        $this->assertCount(15, $samples);
        $this->assertSame(2, array_sum($samples));
        $this->assertSame(1, max($samples));
    }

    public function test_sync_reads_status_labels_and_sprint_item_ids_used_by_the_history(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence('api.monday.com/v2')
            ->push(['data' => ['boards' => [[
                'id' => '123', 'name' => 'Tarefas',
                'columns' => [
                    ['id' => 'status', 'title' => 'Status', 'settings_str' => json_encode(['labels' => ['1' => 'Implementado', '7' => 'Parado']])],
                    ['id' => 'sprint', 'title' => 'Sprint', 'settings_str' => json_encode(['boardIds' => [555]])],
                ],
                'items_page' => ['cursor' => null, 'items' => []],
            ]]]])
            ->push(['data' => ['boards' => [['items_page' => ['items' => [
                ['id' => '9017', 'name' => 'Sprint 17', 'column_values' => [['id' => 'timeline', 'type' => 'timeline', 'from' => '2026-09-28', 'to' => '2026-10-09']]],
                ['id' => '9018', 'name' => 'Sprint 18', 'column_values' => []],
            ]]]]]]);

        $result = app(MondayService::class)->sync('token', '123', ['Sprint' => 'sprint', 'Status' => 'status']);

        $this->assertSame(['1' => 'Implementado', '7' => 'Parado'], $result['labels']['status']);
        $this->assertSame(['9017' => 17, '9018' => 18], $result['sprintItems']);
        $this->assertSame([17], array_column($result['sprints'], 'number'));
    }

    private function timeline(): SprintTimeline
    {
        $connection = $this->configureScenarioConnection();
        $this->seedScenarioHistory();

        return SprintTimeline::fromDatabase($this->scenarioSnapshot(), $connection);
    }
}
