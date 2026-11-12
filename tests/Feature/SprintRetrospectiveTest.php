<?php

namespace Tests\Feature;

use App\Models\DailyAction;
use App\Models\DailyScrum;
use App\Models\MondayConnection;
use App\Models\Retrospective;
use App\Models\RetrospectiveAction;
use App\Models\RetrospectiveActionEvent;
use App\Models\RetrospectiveCard;
use App\Models\User;
use App\Screen;
use App\Services\MondaySnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SprintRetrospectiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00', 'America/Sao_Paulo'));
        $connection = MondayConnection::current();
        $connection->board_id = '123';
        $connection->token = 'token-secreto';
        $connection->column_map = ['Sprint' => 'task_sprint', 'Status' => 'task_status'];
        $connection->save();
        Cache::put(MondaySnapshotService::CACHE_KEY, [
            'syncedAt' => now()->toIso8601String(),
            'rows' => [['Id' => '901', 'Url' => 'https://monday.com/boards/123/pulses/901', 'Nome' => 'Relatório de jornada', 'Status' => 'Implementado', 'Sprint' => 19]],
            'sprints' => [
                ['number' => 18, 'name' => 'Sprint 18', 'start' => '2026-10-13', 'end' => '2026-10-27', 'source' => '', 'completed' => true],
                ['number' => 19, 'name' => 'Sprint 19', 'start' => '2026-10-27', 'end' => '2026-11-10', 'source' => '', 'completed' => true],
                ['number' => 20, 'name' => 'Sprint 20', 'start' => '2026-11-10', 'end' => '2026-11-24', 'source' => '', 'completed' => false],
            ],
            'sprintItems' => [],
        ], 3600);
        Http::preventStrayRequests();
    }

    private function facilitator(array $attributes = []): User
    {
        return User::factory()->withScreens(Screen::SprintRetrospective)->scrumMaster()->create($attributes);
    }

    private function member(array $attributes = []): User
    {
        return User::factory()->withScreens(Screen::SprintRetrospective)->create($attributes);
    }

    public function test_the_retrospective_requires_its_screen(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::Dashboard)->create());
        $this->getJson(route('retro.show'))->assertForbidden();
        $this->getJson(route('retro.room'))->assertForbidden();

        $this->actingAs($this->member());
        $this->getJson(route('retro.show'))->assertOk()->assertJsonPath('canFacilitate', false)->assertJsonPath('sprints.0.number', 20);
    }

    public function test_only_the_scrum_master_starts_and_only_for_a_sprint_closed_on_monday(): void
    {
        $this->actingAs($this->member());
        $this->postJson(route('retro.start'), ['sprint' => 19])->assertUnprocessable()->assertJsonPath('error', 'Só o Maestro Scrum pode iniciar a retrospectiva.');

        $this->actingAs($this->facilitator(['name' => 'Gustavo']));
        $this->postJson(route('retro.start'), ['sprint' => 20])->assertUnprocessable()
            ->assertJsonPath('error', 'A Sprint 20 ainda não foi fechada no Monday (caixa "Concluído?" do cronograma de sprints). Se ela já foi fechada, atualize os dados.');

        $this->postJson(route('retro.start'), ['sprint' => 19])->assertOk()
            ->assertJsonPath('retro.sprint', 19)
            ->assertJsonPath('retro.facilitator.name', 'Gustavo')
            ->assertJsonPath('retro.step', 0)
            ->assertJsonPath('retro.canConduct', true)
            ->assertJsonPath('retro.rules.0.title', '🎯 Foco no processo');

        $this->postJson(route('retro.start'), ['sprint' => 18])->assertUnprocessable()->assertJsonPath('error', 'Encerre a retro da Sprint 19 antes de iniciar outra.');
    }

    public function test_cards_stay_face_down_until_the_reveal_and_then_show_who_wrote_them(): void
    {
        $retro = Retrospective::factory()->create(['sprint' => 19]);
        $ana = $this->member(['name' => 'Ana']);
        $bruno = $this->member(['name' => 'Bruno']);

        $this->actingAs($ana)->postJson(route('retro.cards.store', $retro), ['column' => 'improve', 'text' => 'Dump do banco demora 4h'])->assertOk()
            ->assertJsonPath('retro.cards.0.text', 'Dump do banco demora 4h')
            ->assertJsonPath('retro.cards.0.mine', true);
        $this->actingAs($bruno)->getJson(route('retro.room'))->assertOk()
            ->assertJsonPath('retro.cards.0.hidden', true)
            ->assertJsonPath('retro.cards.0.text', null)
            ->assertJsonPath('retro.cards.0.author', 'Ana');
        $this->postJson(route('retro.reveal', $retro))->assertUnprocessable()->assertJsonPath('error', 'Só o facilitador conduz a retro.');

        $this->actingAs($retro->facilitator)->postJson(route('retro.reveal', $retro))->assertOk();
        $this->actingAs($bruno)->getJson(route('retro.room'))
            ->assertJsonPath('retro.cards.0.hidden', false)
            ->assertJsonPath('retro.cards.0.text', 'Dump do banco demora 4h')
            ->assertJsonPath('retro.cards.0.author', 'Ana');
    }

    public function test_each_person_has_three_votes_after_the_reveal(): void
    {
        $retro = Retrospective::factory()->create(['sprint' => 19]);
        $cards = RetrospectiveCard::query()->insert(collect(range(1, 4))->map(fn (int $index): array => ['retrospective_id' => $retro->id, 'column' => 'improve', 'text' => "Carta {$index}", 'created_at' => now(), 'updated_at' => now()])->all());
        $ids = RetrospectiveCard::query()->orderBy('id')->pluck('id');
        $this->actingAs($this->member());

        $this->postJson(route('retro.cards.vote', $ids[0]))->assertUnprocessable()->assertJsonPath('error', 'A votação começa depois que o facilitador revelar as cartas.');
        $retro->update(['revealed_at' => now()]);
        foreach ($ids->take(3) as $id) {
            $this->postJson(route('retro.cards.vote', $id))->assertOk();
        }
        $this->postJson(route('retro.cards.vote', $ids[3]))->assertUnprocessable()->assertJsonPath('error', 'Você já usou os 3 votos. Tire um voto para votar em outra carta.');
        // Tirar um voto libera outro.
        $this->postJson(route('retro.cards.vote', $ids[0]))->assertOk()->assertJsonPath('retro.votesLeft', 1);
        $this->postJson(route('retro.cards.vote', $ids[3]))->assertOk()->assertJsonPath('retro.votesLeft', 0)->assertJsonPath('retro.cards.3.votes', 1)->assertJsonPath('retro.cards.3.myVote', true);
    }

    public function test_steps_keep_the_time_of_each_step_and_voted_cards_become_discussion_topics(): void
    {
        $retro = Retrospective::factory()->create(['sprint' => 19, 'step' => 3, 'step_started_at' => now(), 'revealed_at' => now()]);
        $voted = $retro->cards()->create(['column' => 'improve', 'text' => 'Card de suporte sem passos']);
        $voted->votes()->create(['user_id' => $this->member()->id]);
        $retro->cards()->create(['column' => 'keep', 'text' => 'Sem votos']);
        $this->actingAs($retro->facilitator);

        $this->travel(13)->minutes();
        $this->postJson(route('retro.step', $retro), ['step' => 4])->assertOk()
            ->assertJsonPath('retro.step', 4)
            ->assertJsonPath('retro.steps.3.seconds', 780)
            ->assertJsonPath('retro.steps.3.minutes', 12)
            ->assertJsonCount(1, 'retro.topics')
            ->assertJsonPath('retro.topics.0.title', 'Card de suporte sem passos')
            ->assertJsonPath('retro.topics.0.votes', 1);
        // Voltar e avançar de novo não duplica o tema.
        $this->postJson(route('retro.step', $retro), ['step' => 3])->assertOk();
        $this->postJson(route('retro.step', $retro), ['step' => 4])->assertOk()->assertJsonCount(1, 'retro.topics');
    }

    public function test_at_most_two_focus_one_topic_each(): void
    {
        $retro = Retrospective::factory()->create(['sprint' => 19]);
        [$first, $second, $third] = collect(['A', 'B', 'C'])->map(fn (string $title) => $retro->topics()->create(['title' => $title]))->all();
        $this->actingAs($retro->facilitator);

        $this->postJson(route('retro.topics.update', $first), ['focus' => 1])->assertOk();
        $this->postJson(route('retro.topics.update', $second), ['focus' => 2])->assertOk();
        $this->postJson(route('retro.topics.update', $third), ['focus' => 1])->assertOk();
        $this->postJson(route('retro.topics.update', $third), ['focus' => 3])->assertUnprocessable();

        $this->assertNull($first->fresh()->focus);
        $this->assertSame([2, 1], [$second->fresh()->focus, $third->fresh()->focus]);
    }

    public function test_closing_freezes_the_metrics_and_the_actions_go_to_follow_up(): void
    {
        $retro = Retrospective::factory()->create(['sprint' => 19]);
        $rafael = $this->member(['name' => 'Rafael']);
        $this->actingAs($retro->facilitator);

        $this->postJson(route('retro.actions.store', $retro), ['description' => 'Testar dump parcial', 'responsible_id' => $rafael->id, 'due_date' => '2026-11-20', 'signal' => 'Restore abaixo de 1h', 'focus' => 1, 'item_id' => '901'])->assertOk()
            ->assertJsonPath('retro.actions.0.item.name', 'Relatório de jornada')
            ->assertJsonPath('retro.actions.0.responsible.name', 'Rafael');
        $this->postJson(route('retro.close', $retro), ['metrics' => ['delivered' => 62, 'scope' => 85]])->assertOk()->assertJsonPath('retro', null);

        $this->assertSame(['delivered' => 62, 'scope' => 85], $retro->fresh()->metrics);
        $this->getJson(route('retro.show'))->assertOk()
            ->assertJsonPath('kpis.open', 1)
            ->assertJsonPath('actions.0.description', 'Testar dump parcial')
            ->assertJsonPath('sprints.1.retro.status', 'closed')
            ->assertJsonPath('sprints.1.retro.actions.total', 1);
        $this->getJson(route('retro.review', 19))->assertOk()->assertJsonPath('retro.metrics.delivered', 62)->assertJsonPath('retro.canConduct', false);
        $this->postJson(route('retro.cards.store', $retro), ['column' => 'keep', 'text' => 'Depois'])->assertUnprocessable()->assertJsonPath('error', 'Esta retro já foi encerrada.');
    }

    public function test_only_the_responsible_and_the_facilitator_conclude_an_action(): void
    {
        $responsible = $this->member(['name' => 'Rafael']);
        $action = RetrospectiveAction::factory()->create(['responsible_id' => $responsible->id]);
        $this->actingAs($this->member());

        $this->postJson(route('retro.actions.complete', $action), ['result' => 'Feito'])->assertUnprocessable()
            ->assertJsonPath('error', 'Só o responsável pela ação e o facilitador da retro podem alterar a ação.');
        $this->getJson(route('retro.show'))->assertJsonPath('actions.0.canManage', false);

        $this->actingAs($responsible)->postJson(route('retro.actions.complete', $action), ['result' => 'Restore em 40 min', 'signal_reached' => true])->assertOk()
            ->assertJsonPath('kpis.done', 1)->assertJsonPath('kpis.signalReached', 1)->assertJsonPath('kpis.rate', 100);
        $this->actingAs($action->retrospective->facilitator)->postJson(route('retro.actions.reopen', $action))->assertOk()->assertJsonPath('kpis.open', 1);
        $this->assertSame([RetrospectiveActionEvent::DONE, RetrospectiveActionEvent::REOPENED], $action->events()->pluck('type')->all());
    }

    public function test_an_action_is_late_from_the_day_after_the_due_date(): void
    {
        RetrospectiveAction::factory()->create(['due_date' => '2026-11-12']);
        RetrospectiveAction::factory()->create(['due_date' => '2026-11-09']);
        RetrospectiveAction::factory()->done()->create(['due_date' => '2026-11-01']);
        $this->actingAs($this->member());

        $this->getJson(route('retro.show'))->assertOk()
            ->assertJsonPath('kpis', ['open' => 1, 'late' => 1, 'done' => 1, 'signalReached' => 1, 'dropped' => 0, 'total' => 3, 'rate' => 33])
            ->assertJsonPath('actions.0.late', true)
            ->assertJsonPath('actions.0.lateDays', 3);
    }

    public function test_the_next_retro_reviews_the_open_actions_of_the_previous_ones(): void
    {
        $previous = RetrospectiveAction::factory()->create(['description' => 'Template de suporte', 'due_date' => '2026-11-01']);
        $retro = Retrospective::factory()->create(['sprint' => 19]);
        $this->actingAs($retro->facilitator);

        $this->getJson(route('retro.room'))->assertJsonPath('retro.previousActions.0.description', 'Template de suporte')->assertJsonPath('retro.previousActions.0.canManage', true);
        $this->postJson(route('retro.actions.keep', $previous), ['due_date' => '2026-11-30', 'note' => 'Segue com novo prazo', 'from' => 'room'])->assertOk()
            ->assertJsonPath('retro.previousActions.0.dueDate', '2026-11-30')
            ->assertJsonPath('retro.previousActions.0.review.type', 'continued')
            ->assertJsonPath('retro.previousActions.0.review.note', 'Segue com novo prazo');
        $this->postJson(route('retro.actions.drop', $previous), ['reason' => 'Perdeu o sentido', 'from' => 'room'])->assertOk()
            ->assertJsonPath('retro.previousActions.0.status', 'dropped')
            ->assertJsonPath('retro.previousActions.0.review.type', 'dropped');
    }

    public function test_open_retro_actions_show_up_in_every_daily_until_they_are_done(): void
    {
        $responsible = User::factory()->withScreens(Screen::DailyScrum)->create(['name' => 'Rafael']);
        $action = RetrospectiveAction::factory()->create(['description' => 'Testar dump parcial', 'responsible_id' => $responsible->id, 'retrospective_id' => Retrospective::factory()->closed()->create(['sprint' => 18])]);
        RetrospectiveAction::factory()->done()->create(['description' => 'Já concluída']);
        $this->actingAs($responsible);

        $this->getJson(route('daily.show', ['sprint' => 20]))->assertOk()
            ->assertJsonCount(1, 'retroActions')
            ->assertJsonPath('retroActions.0.description', 'Testar dump parcial')
            ->assertJsonPath('retroActions.0.retro.sprint', 18)
            ->assertJsonPath('retroActions.0.canManage', true);
        $this->postJson(route('daily.retro-actions.complete', $action), ['sprint' => 20, 'result' => 'Feito na Sprint 20', 'signal_reached' => true])->assertOk()
            ->assertJsonCount(0, 'retroActions');
        $this->assertSame(RetrospectiveAction::DONE, $action->fresh()->status);
    }

    public function test_the_data_step_brings_the_daily_responsibilities_of_the_sprint(): void
    {
        $daily = DailyScrum::factory()->create(['sprint' => 19, 'date' => '2026-11-05']);
        DailyAction::factory()->for($daily, 'daily')->done()->create(['opened_at' => now()->subHours(2), 'closed_at' => now()]);
        DailyAction::factory()->for($daily, 'daily')->create();
        $this->actingAs($this->member());

        $this->getJson(route('retro.review', 19))->assertOk()
            ->assertJsonPath('retro', null)
            ->assertJsonPath('sprint.completed', true)
            ->assertJsonPath('dailyStats', ['total' => 2, 'done' => 1, 'averageMinutes' => 120]);
    }
}
