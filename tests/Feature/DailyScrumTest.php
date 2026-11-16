<?php

namespace Tests\Feature;

use App\Models\DailyAction;
use App\Models\DailyActionEvent;
use App\Models\DailyScrum;
use App\Models\MondayActivityEvent;
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
            ->assertJsonPath('dailies.0.date', '2026-11-12')
            ->assertJsonPath('dailies.0.actions', 2)
            ->assertJsonCount(1, 'dailies');

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

    public function test_the_daily_time_runs_from_start_to_close_while_presence_lists_who_was_there(): void
    {
        $ana = User::factory()->withScreens(Screen::DailyScrum)->create(['name' => 'Ana']);
        $bruno = User::factory()->withScreens(Screen::DailyScrum)->create(['name' => 'Bruno']);

        // Abrir a tela registra a presença, mas o tempo só começa no Iniciar Daily.
        $this->actingAs($ana)->getJson(route('daily.show'))->assertOk()
            ->assertJsonPath('presence.present', ['Ana'])->assertJsonPath('presence.status', 'idle')->assertJsonPath('presence.seconds', 0);
        $this->travel(3)->minutes();
        $this->actingAs($ana)->postJson(route('daily.start'), ['sprint' => 20])->assertOk()
            ->assertJsonPath('presence.status', 'running')->assertJsonPath('presence.live', true)->assertJsonPath('presence.seconds', 0);
        $this->travel(5)->minutes();
        $this->actingAs($bruno)->postJson(route('daily.presence'), ['sprint' => 20])->assertOk()
            ->assertJsonPath('presence.attendees', ['Ana', 'Bruno'])->assertJsonPath('presence.seconds', 300);
        // Um segundo clique em Iniciar não zera o tempo.
        $this->actingAs($bruno)->postJson(route('daily.start'), ['sprint' => 20])->assertOk()->assertJsonPath('presence.seconds', 300);
        $this->travel(9)->minutes();
        $this->actingAs($bruno)->postJson(route('daily.close'), ['sprint' => 20])->assertOk()
            ->assertJsonPath('presence.status', 'ended')->assertJsonPath('presence.live', false)->assertJsonPath('presence.seconds', 840)
            ->assertJsonPath('presence.startedBy', 'Ana')->assertJsonPath('presence.closedBy', 'Bruno')->assertJsonPath('presence.closedAt', now()->toIso8601String());

        // Encerrada, o tempo para mesmo com gente na tela.
        $this->travel(20)->minutes();
        $this->actingAs($ana)->postJson(route('daily.presence'), ['sprint' => 20])->assertOk()
            ->assertJsonPath('presence.present', ['Ana'])->assertJsonPath('presence.seconds', 840);
        $this->actingAs(User::factory()->withScreens(Screen::Planning)->create())->getJson(route('planning.review', 20))->assertOk()
            ->assertJsonPath('dailies.0.attendees', ['Ana', 'Bruno'])
            ->assertJsonPath('dailies.0.seconds', 840)
            ->assertJsonPath('dailies.0.live', false);

        // Retomada: o novo trecho soma ao tempo já registrado.
        $this->actingAs($ana)->postJson(route('daily.start'), ['sprint' => 20])->assertOk()->assertJsonPath('presence.status', 'running');
        $this->travel(2)->minutes();
        $this->actingAs($ana)->postJson(route('daily.close'), ['sprint' => 20])->assertOk()->assertJsonPath('presence.seconds', 960);
        $this->assertSame(1, DailyScrum::count());
        $this->assertSame($ana->id, DailyScrum::first()->started_by);
        $this->assertSame($ana->id, DailyScrum::first()->closed_by);
    }

    public function test_the_minutes_show_what_changed_in_each_card_since_the_previous_daily(): void
    {
        $connection = MondayConnection::current();
        $connection->column_map = [...$connection->column_map, 'RealH' => 'real_col'];
        $connection->save();
        // Daily de ontem encerrada às 09:15; o que mudou depois disso entra na ata de hoje.
        DailyScrum::factory()->create(['date' => '2026-11-11', 'started_at' => '2026-11-11 12:00:00', 'closed_at' => '2026-11-11 12:15:00']);
        $event = fn (string $column, array $before, array $after, string $at) => MondayActivityEvent::factory()->create([
            'board_id' => '123', 'item_id' => '901', 'event' => 'update_column_value', 'column_id' => $column, 'previous_value' => $before, 'value' => $after,
            'data' => ['pulse_id' => 901, 'pulse_name' => 'Relatório de jornada', 'column_id' => $column], 'occurred_at' => CarbonImmutable::parse($at, 'America/Sao_Paulo')->utc(),
        ]);
        $event('task_status', ['label' => ['text' => 'Em progresso']], ['label' => ['text' => 'Parado']], '2026-11-11 14:00');
        $event('real_col', ['value' => '1'], ['value' => '3'], '2026-11-11 16:00');
        Http::fake(['api.monday.com/v2' => Http::response(['data' => ['items' => [
            ['id' => '901', 'updates' => [
                ['id' => '1', 'text_body' => 'Travado aguardando acesso ao banco.', 'created_at' => '2026-11-11T20:00:00Z', 'creator' => ['name' => 'Ana'], 'replies' => [
                    ['id' => '2', 'body' => '<p>Pedi o acesso para a Infra.</p>', 'created_at' => '2026-11-12T11:00:00Z', 'creator' => ['name' => 'Gustavo']],
                ]],
                ['id' => '3', 'text_body' => 'Comentário de antes da Daily de ontem.', 'created_at' => '2026-11-10T15:00:00Z', 'creator' => ['name' => 'Ana'], 'replies' => []],
            ]],
            ['id' => '902', 'updates' => []],
        ]]])]);
        $this->actingAs(User::factory()->withScreens(Screen::DailyScrum)->create());

        $response = $this->getJson(route('daily.minutes', ['sprint' => 20, 'date' => '2026-11-12']))->assertOk()
            ->assertJsonPath('fromLabel', 'a Daily de 11/11')
            ->assertJsonPath('from', '2026-11-11T12:15:00+00:00')
            ->assertJsonPath('commentsAvailable', true);
        $cards = collect($response->json('cards'))->keyBy('id');
        $this->assertEquals(['status' => 'Em progresso', 'estimate' => 8, 'real' => 1], $cards['901']['before']);
        $this->assertSame('Parado', $cards['901']['status']);
        $this->assertEquals(3, $cards['901']['real']);
        $this->assertSame(['Ana', 'Gustavo'], array_column($cards['901']['comments'], 'author'));
        $this->assertSame('Pedi o acesso para a Infra.', $cards['901']['comments'][1]['text']);
        $this->assertSame('Em progresso', $cards['902']['before']['status']);
        $this->assertSame([], $cards['902']['comments']);
    }

    public function test_without_a_previous_daily_the_minutes_start_at_the_previous_working_day(): void
    {
        Http::fake(['api.monday.com/v2' => Http::response(['data' => ['items' => []]])]);
        $this->actingAs(User::factory()->withScreens(Screen::DailyScrum)->create());

        // Segunda-feira: o dia útil anterior é a sexta.
        $this->getJson(route('daily.minutes', ['sprint' => 20, 'date' => '2026-11-16']))->assertOk()
            ->assertJsonPath('fromLabel', 'o início de 13/11')
            ->assertJsonPath('from', '2026-11-13T03:00:00+00:00')
            ->assertJsonPath('commentsAvailable', false);
    }

    public function test_closing_requires_a_started_daily_and_only_today_can_be_started(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::DailyScrum)->create());

        $this->postJson(route('daily.close'), ['sprint' => 20])->assertUnprocessable()->assertJsonPath('error', 'A Daily de hoje ainda não foi iniciada.');
        $this->postJson(route('daily.start'), ['sprint' => 19])->assertUnprocessable()->assertJsonPath('error', 'Só dá para iniciar a Daily de hoje da sprint atual.');
        $this->assertSame(0, DailyScrum::count());
    }

    public function test_a_daily_left_running_on_a_past_day_counts_until_the_last_presence(): void
    {
        $daily = DailyScrum::factory()->create(['date' => '2026-11-11', 'started_at' => '2026-11-11 12:30:00', 'opened_at' => '2026-11-11 12:30:00']);
        $daily->participants()->create(['user_id' => User::factory()->create()->id, 'first_seen_at' => '2026-11-11 12:28:00', 'last_seen_at' => '2026-11-11 12:48:00']);

        $presence = app(DailyService::class)->presenceOf($daily->fresh());

        $this->assertSame(1080, $presence['seconds']);
        $this->assertSame('ended', $presence['status']);
        $this->assertFalse($presence['live']);
    }

    public function test_looking_at_a_past_sprint_does_not_count_as_presence(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::DailyScrum)->create());

        $this->postJson(route('daily.presence'), ['sprint' => 19])->assertOk()->assertJsonPath('presence', null);
        $this->getJson(route('daily.show', ['sprint' => 19]))->assertOk()->assertJsonPath('presence', null)->assertJsonPath('dailies', []);
        $this->assertSame(0, DailyScrum::count());
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
