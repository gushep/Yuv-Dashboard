<?php

namespace Tests\Feature;

use App\Models\DailyAction;
use App\Models\DailyActionEvent;
use App\Models\DailyScrum;
use App\Models\MondayConnection;
use App\Models\PlanningSession;
use App\Models\User;
use App\Screen;
use App\Services\DailyService;
use App\Services\MondaySnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DailyScrumTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 09:30', 'America/Sao_Paulo'));
        $connection = MondayConnection::current();
        $connection->board_id = '123';
        $connection->token = 'token-secreto';
        $connection->column_map = ['Sprint' => 'task_sprint', 'Status' => 'task_status', 'Tipo' => 'task_type', 'EstH' => 'est_col'];
        $connection->save();
        Cache::put(MondaySnapshotService::CACHE_KEY, [
            'syncedAt' => now()->toIso8601String(),
            'rows' => [
                ['Id' => '901', 'Url' => 'https://monday.com/boards/123/pulses/901', 'Nome' => 'Relatório de jornada', 'Tipo' => 'Melhoria', 'Status' => 'Parado', 'Responsavel' => 'Ana', 'Sprint' => 20, 'EstH' => 8.0, 'RealH' => 3.0],
                ['Id' => '902', 'Url' => '', 'Nome' => 'Login por FaceID', 'Tipo' => 'Melhoria', 'Status' => 'Em progresso', 'Responsavel' => 'Bruno', 'Sprint' => 20, 'EstH' => 5.0, 'RealH' => 1.0],
            ],
            'sprints' => [
                ['number' => 19, 'name' => 'Sprint 19', 'start' => '2026-10-27', 'end' => '2026-11-09', 'source' => ''],
                ['number' => 20, 'name' => 'Sprint 20', 'start' => '2026-11-10', 'end' => '2026-11-21', 'source' => ''],
            ],
            'sprintItems' => [],
        ], 3600);
        Http::preventStrayRequests();
    }

    public function test_daily_hub_requires_the_daily_scrum_screen(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::Dashboard)->create());
        $this->getJson(route('daily.show'))->assertForbidden();
        $this->postJson(route('daily.actions.store'), ['sprint' => 20, 'description' => 'x'])->assertForbidden();

        $this->actingAs(User::factory()->withScreens(Screen::DailyScrum)->create());
        $this->getJson(route('daily.show'))->assertOk();
    }

    public function test_daily_hub_shows_the_sprint_in_progress_with_the_goal_registered_in_the_planning(): void
    {
        PlanningSession::factory()->closed()->create(['goal' => 'Entregar o relatório de jornada.']);
        $this->actingAs(User::factory()->withScreens(Screen::DailyScrum)->create(['name' => 'Gustavo']));

        $this->getJson(route('daily.show'))->assertOk()
            ->assertJsonPath('sprint', 20)
            ->assertJsonPath('date', '2026-11-12')
            ->assertJsonPath('goal', 'Entregar o relatório de jornada.')
            ->assertJsonPath('sprints.0.number', 20)
            ->assertJsonPath('canPublish', false)
            ->assertJsonFragment(['name' => 'Gustavo']);

        $this->getJson(route('daily.show', ['sprint' => 19]))->assertOk()->assertJsonPath('sprint', 19)->assertJsonPath('goal', null);
    }

    public function test_actions_open_on_the_daily_of_the_day_with_an_optional_card(): void
    {
        $responsible = User::factory()->create(['name' => 'Bruno']);
        $this->actingAs(User::factory()->withScreens(Screen::DailyScrum)->create());

        $this->postJson(route('daily.actions.store'), ['sprint' => 20, 'description' => '  Liberar acesso ao ambiente de QA  ', 'responsible_id' => $responsible->id])->assertOk()
            ->assertJsonPath('actions.0.description', 'Liberar acesso ao ambiente de QA')
            ->assertJsonPath('actions.0.responsible.name', 'Bruno')
            ->assertJsonPath('actions.0.item', null)
            ->assertJsonPath('actions.0.origin', '2026-11-12')
            ->assertJsonPath('actions.0.status', 'pending');
        $this->postJson(route('daily.actions.store'), ['sprint' => 20, 'description' => 'Destravar o relatório', 'item_id' => '901'])->assertOk()
            ->assertJsonPath('actions.1.item', ['id' => '901', 'name' => 'Relatório de jornada', 'url' => 'https://monday.com/boards/123/pulses/901'])
            ->assertJsonPath('dailies', [['date' => '2026-11-12', 'actions' => 2]]);

        $this->assertSame(1, DailyScrum::count());
        $this->assertSame(DailyActionEvent::OPENED, DailyAction::first()->events()->value('type'));
        $this->postJson(route('daily.actions.store'), ['sprint' => 20, 'description' => 'x', 'item_id' => '555'])->assertUnprocessable()
            ->assertJsonPath('error', 'Card não encontrado nos dados sincronizados do Monday. Atualize os dados e tente de novo.');
        $this->postJson(route('daily.actions.store'), ['sprint' => 20, 'description' => ''])->assertUnprocessable()->assertJsonValidationErrors(['description' => 'Descreva a ação ou a questão.']);
        // Sem a permissão Atualizar cards, a responsabilidade com card fica só no app.
        $linked = DailyAction::query()->whereNotNull('item_id')->first();
        $this->assertNull($linked->notice_status);
        $this->postJson(route('daily.actions.notice', $linked))->assertUnprocessable()->assertJsonPath('error', 'Comentar no card do Monday exige a permissão "Atualizar cards".');
        Http::assertNothingSent();
    }

    public function test_opening_with_a_linked_card_comments_it_on_monday_as_pending_once(): void
    {
        $responsible = User::factory()->create(['name' => 'Bruno']);
        $this->fakeMondayComment('6001');
        $this->actingAs(User::factory()->withScreens(Screen::DailyScrum)->canUpdateItems()->create(['name' => 'Gustavo']));

        $this->postJson(route('daily.actions.store'), ['sprint' => 20, 'description' => 'Destravar o relatório', 'responsible_id' => $responsible->id, 'item_id' => '901'])->assertOk()
            ->assertJsonPath('actions.0.status', 'pending')
            ->assertJsonPath('actions.0.noticeStatus', 'published')
            ->assertJsonPath('actions.0.noticeUpdateId', '6001');

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'create_update') && $request['variables']['item'] === '901'
            && str_contains($request['variables']['body'], '📋 DAILY SCRUM — NOVA PENDÊNCIA')
            && str_contains($request['variables']['body'], '🔴 <strong>Questão:</strong> Destravar o relatório')
            && str_contains($request['variables']['body'], '👤 <strong>Responsável:</strong> Bruno')
            && str_contains($request['variables']['body'], '📅 <strong>Abertura:</strong> 12/11/2026 09:30 · Daily de 12/11 · Sprint 20')
            && str_contains($request['variables']['body'], '🙋 <strong>Aberta por:</strong> Gustavo')
            && str_contains($request['variables']['body'], '⏳ <strong>Status:</strong> Pendente'));
        $action = DailyAction::sole();
        $this->assertSame([DailyActionEvent::OPENED, DailyActionEvent::NOTICED], $action->events()->pluck('type')->all());

        // Um novo clique não duplica o comentário.
        $this->postJson(route('daily.actions.notice', $action))->assertUnprocessable()->assertJsonPath('error', 'Esta responsabilidade já foi avisada no card.');
        Http::assertSentCount(2);
    }

    public function test_monday_failure_on_the_opening_comment_keeps_the_action_and_can_be_retried(): void
    {
        // Monday: recusa o primeiro comentário; na nova tentativa, aceita.
        Http::fakeSequence('api.monday.com/v2')
            ->push(['errors' => [['message' => 'Sem permissão']]])
            ->push(['data' => ['items' => [['id' => '901', 'board' => ['id' => '123']]]]])
            ->push(['data' => ['create_update' => ['id' => '6002']]]);
        $this->actingAs(User::factory()->withScreens(Screen::DailyScrum)->canUpdateItems()->create());

        $this->postJson(route('daily.actions.store'), ['sprint' => 20, 'description' => 'Destravar o relatório', 'item_id' => '901'])->assertOk()
            ->assertJsonPath('actions.0.status', 'pending')
            ->assertJsonPath('actions.0.noticeStatus', 'failed');
        $action = DailyAction::sole();
        $this->assertSame(DailyActionEvent::NOTICE_FAILED, $action->events()->reorder('id', 'desc')->value('type'));

        $this->postJson(route('daily.actions.notice', $action))->assertOk()->assertJsonPath('actions.0.noticeStatus', 'published')->assertJsonPath('actions.0.noticeUpdateId', '6002');
    }

    public function test_pending_actions_carry_over_to_the_next_dailies_without_being_duplicated(): void
    {
        $yesterday = DailyScrum::factory()->create(['date' => '2026-11-11']);
        DailyAction::factory()->for($yesterday, 'daily')->create(['description' => 'Pendência de ontem', 'opened_at' => now()->subDay()]);
        DailyAction::factory()->for($yesterday, 'daily')->done()->create(['description' => 'Resolvida ontem', 'opened_at' => now()->subDay()]);
        $this->actingAs(User::factory()->withScreens(Screen::DailyScrum)->create());
        $this->postJson(route('daily.actions.store'), ['sprint' => 20, 'description' => 'Nova de hoje'])->assertOk();

        $today = $this->getJson(route('daily.show'))->assertOk()->json('actions');
        $this->assertSame(['Pendência de ontem', 'Resolvida ontem', 'Nova de hoje'], array_column($today, 'description'));
        $this->assertSame(['2026-11-11', '2026-11-11', '2026-11-12'], array_column($today, 'origin'));
        $this->assertSame(3, DailyAction::count());

        // Consultando a Daily de ontem, a ação aberta hoje ainda não existia.
        $this->assertSame(['Pendência de ontem', 'Resolvida ontem'], array_column($this->getJson(route('daily.show', ['date' => '2026-11-11']))->json('actions'), 'description'));
    }

    public function test_done_records_the_resolution_and_the_time_without_touching_monday(): void
    {
        $action = DailyAction::factory()->forItem()->create(['opened_at' => now()->subHours(26)->subMinutes(5)]);
        $this->actingAs(User::factory()->withScreens(Screen::DailyScrum)->create(['name' => 'Ana']));

        $this->postJson(route('daily.actions.complete', $action), ['resolution' => ''])->assertUnprocessable()->assertJsonValidationErrors(['resolution' => 'Descreva a resolução para concluir a ação.']);
        $this->postJson(route('daily.actions.complete', $action), ['resolution' => 'Ambiente liberado.', 'publish' => false])->assertOk()
            ->assertJsonPath('actions.0.status', 'done')
            ->assertJsonPath('actions.0.resolution', 'Ambiente liberado.')
            ->assertJsonPath('actions.0.closedBy', 'Ana')
            ->assertJsonPath('actions.0.elapsed', '1d 2h 05min')
            ->assertJsonPath('actions.0.publishStatus', null);

        $this->assertSame([DailyActionEvent::DONE], $action->events()->pluck('type')->all());
        $this->postJson(route('daily.actions.complete', $action), ['resolution' => 'De novo'])->assertUnprocessable()->assertJsonPath('error', 'Esta ação já foi concluída.');
        Http::assertNothingSent();
    }

    public function test_done_with_a_linked_card_publishes_the_resolution_once_on_monday(): void
    {
        $responsible = User::factory()->create(['name' => 'Bruno']);
        $action = DailyAction::factory()->forItem()->create(['description' => 'Destravar o relatório', 'responsible_id' => $responsible->id, 'opened_at' => now()->subHours(23)->subMinutes(30)]);
        $this->fakeMondayComment('5001');
        $this->actingAs(User::factory()->withScreens(Screen::DailyScrum)->canUpdateItems()->create(['name' => 'Gustavo']));

        $this->postJson(route('daily.actions.complete', $action), ['resolution' => 'Consulta otimizada.', 'publish' => true])->assertOk()
            ->assertJsonPath('actions.0.publishStatus', 'published')
            ->assertJsonPath('actions.0.mondayUpdateId', '5001');

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'create_update') && $request['variables']['item'] === '901'
            && str_contains($request['variables']['body'], '📋 DAILY SCRUM — RESOLUÇÃO DE PENDÊNCIA')
            && str_contains($request['variables']['body'], '🔴 <strong>Questão:</strong> Destravar o relatório')
            && str_contains($request['variables']['body'], '👤 <strong>Responsável:</strong> Bruno')
            && str_contains($request['variables']['body'], '📅 <strong>Abertura:</strong> 11/11/2026 10:00')
            && str_contains($request['variables']['body'], '✅ <strong>Conclusão:</strong> 12/11/2026 09:30')
            && str_contains($request['variables']['body'], '⏱️ <strong>Tempo decorrido:</strong> 23h 30min')
            && str_contains($request['variables']['body'], '🛠️ <strong>Resolução:</strong> Consulta otimizada.')
            && str_contains($request['variables']['body'], 'Enviado por Gustavo'));
        $this->assertSame([DailyActionEvent::DONE, DailyActionEvent::PUBLISHED], $action->events()->pluck('type')->all());

        // Nova tentativa não duplica o comentário.
        $this->postJson(route('daily.actions.publish', $action))->assertUnprocessable()->assertJsonPath('error', 'Esta conclusão já foi publicada no card.');
        Http::assertSentCount(2);
    }

    public function test_publishing_requires_the_update_cards_permission(): void
    {
        $action = DailyAction::factory()->forItem()->create();
        $this->actingAs(User::factory()->withScreens(Screen::DailyScrum)->create());

        $this->postJson(route('daily.actions.complete', $action), ['resolution' => 'Feito.', 'publish' => true])->assertUnprocessable()
            ->assertJsonPath('error', 'Publicar no card do Monday exige a permissão "Atualizar cards". Conclua sem publicar ou peça a permissão.');
        $this->assertSame(DailyAction::PENDING, $action->fresh()->status);
        // Sem card vinculado não há o que publicar: conclui normalmente.
        $loose = DailyAction::factory()->create();
        $this->postJson(route('daily.actions.complete', $loose), ['resolution' => 'Feito.', 'publish' => true])->assertOk();
        $this->assertNull($loose->fresh()->publish_status);
        Http::assertNothingSent();
    }

    public function test_monday_failure_keeps_the_action_done_and_the_publication_can_be_retried(): void
    {
        $action = DailyAction::factory()->forItem()->create();
        // Monday: recusa a primeira publicação; na nova tentativa, aceita.
        Http::fakeSequence('api.monday.com/v2')
            ->push(['errors' => [['message' => 'Sem permissão']]])
            ->push(['data' => ['items' => [['id' => '901', 'board' => ['id' => '123']]]]])
            ->push(['data' => ['create_update' => ['id' => '5002']]]);
        $this->actingAs(User::factory()->withScreens(Screen::DailyScrum)->canUpdateItems()->create());

        $this->postJson(route('daily.actions.complete', $action), ['resolution' => 'Feito.', 'publish' => true])->assertOk()
            ->assertJsonPath('actions.0.status', 'done')
            ->assertJsonPath('actions.0.publishStatus', 'failed');
        $this->assertSame(DailyActionEvent::PUBLISH_FAILED, $action->events()->reorder('id', 'desc')->value('type'));

        $this->postJson(route('daily.actions.publish', $action))->assertOk()->assertJsonPath('actions.0.publishStatus', 'published')->assertJsonPath('actions.0.mondayUpdateId', '5002');
    }

    public function test_reopening_keeps_the_previous_conclusion_in_the_history(): void
    {
        $action = DailyAction::factory()->forItem()->create();
        $this->fakeMondayComment('5003');
        $this->actingAs(User::factory()->withScreens(Screen::DailyScrum)->canUpdateItems()->create());
        $this->postJson(route('daily.actions.complete', $action), ['resolution' => 'Primeira solução.', 'publish' => true])->assertOk();

        $this->postJson(route('daily.actions.reopen', $action))->assertOk()
            ->assertJsonPath('actions.0.status', 'pending')
            ->assertJsonPath('actions.0.resolution', null)
            ->assertJsonPath('actions.0.publishStatus', null)
            ->assertJsonPath('actions.0.reopened', 1)
            ->assertJsonPath('actions.0.events.0.resolution', 'Primeira solução.')
            ->assertJsonPath('actions.0.events.1.mondayUpdateId', '5003')
            ->assertJsonPath('actions.0.events.2.type', 'reopened');
        $this->postJson(route('daily.actions.reopen', $action))->assertUnprocessable()->assertJsonPath('error', 'Esta ação já está pendente.');
    }

    public function test_the_sprint_history_shows_the_dailies_with_their_actions(): void
    {
        $daily = DailyScrum::factory()->create(['sprint' => 19, 'date' => '2026-11-05']);
        DailyAction::factory()->for($daily, 'daily')->done('Resolvido na hora.')->create(['description' => 'Pendência da 19']);
        $this->actingAs(User::factory()->withScreens(Screen::Planning)->create());

        $this->getJson(route('planning.review', 19))->assertOk()
            ->assertJsonPath('dailies.0.date', '2026-11-05')
            ->assertJsonPath('dailies.0.actions.0.description', 'Pendência da 19')
            ->assertJsonPath('dailies.0.actions.0.resolution', 'Resolvido na hora.');
    }

    public function test_elapsed_time_reads_in_days_hours_and_minutes(): void
    {
        $from = CarbonImmutable::parse('2026-11-10 08:00');

        $this->assertSame('05min', DailyService::elapsed($from, $from->addMinutes(5)));
        $this->assertSame('2h 00min', DailyService::elapsed($from, $from->addHours(2)));
        $this->assertSame('3d 0h 15min', DailyService::elapsed($from, $from->addDays(3)->addMinutes(15)));
    }

    /** Monday: item do card (quadro) e criação do comentário. */
    private function fakeMondayComment(string $updateId): void
    {
        Http::fake(['api.monday.com/v2' => Http::sequence()
            ->push(['data' => ['items' => [['id' => '901', 'board' => ['id' => '123']]]]])
            ->push(['data' => ['create_update' => ['id' => $updateId]]])]);
    }
}
