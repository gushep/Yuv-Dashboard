<?php

namespace Tests\Feature;

use App\Models\MondayConnection;
use App\Models\PlanningRound;
use App\Models\PlanningSession;
use App\Models\PlanningVote;
use App\Models\User;
use App\PlanningStatus;
use App\Screen;
use App\Services\MondayService;
use App\Services\MondaySnapshotService;
use App\Services\PlanningService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlanningPokerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $connection = MondayConnection::current();
        $connection->board_id = '123';
        $connection->token = 'token-secreto';
        $connection->column_map = ['Sprint' => 'task_sprint', 'Status' => 'task_status', 'Tipo' => 'task_type', 'EstH' => 'est_col'];
        $connection->save();
        Cache::put(MondaySnapshotService::CACHE_KEY, [
            'syncedAt' => now()->toIso8601String(),
            'rows' => [
                ['Id' => '10', 'Url' => '', 'Nome' => 'Relatório de frota', 'Tipo' => 'Melhoria', 'Status' => 'Implementado', 'Responsavel' => 'Ana', 'Sprint' => 19, 'EstH' => 8.0, 'RealH' => 10.5],
                ['Id' => '11', 'Url' => '', 'Nome' => 'Ajuste de filtro', 'Tipo' => 'Bug', 'Status' => 'Implementado', 'Responsavel' => 'Bruno', 'Sprint' => 19, 'EstH' => 2.0, 'RealH' => 1.5],
                ['Id' => '12', 'Url' => '', 'Nome' => 'Card cancelado', 'Tipo' => 'Bug', 'Status' => 'Cancelado', 'Responsavel' => 'Bruno', 'Sprint' => 19, 'EstH' => 5.0, 'RealH' => 0.0],
                ['Id' => '13', 'Url' => '', 'Nome' => 'Tela antiga', 'Tipo' => 'Melhoria', 'Status' => 'Implementado', 'Responsavel' => 'Ana', 'Sprint' => 18, 'EstH' => 3.0, 'RealH' => 4.0],
            ],
            'sprints' => [
                ['number' => 18, 'name' => 'Sprint 18', 'start' => '2026-10-13', 'end' => '2026-10-24', 'source' => ''],
                ['number' => 19, 'name' => 'Sprint 19', 'start' => '2026-10-27', 'end' => '2026-11-10', 'source' => ''],
                ['number' => 20, 'name' => 'Sprint 20', 'start' => '2026-11-11', 'end' => '2026-11-24', 'source' => ''],
            ],
            'sprintItems' => [],
            'backlog' => [
                ['Id' => '901', 'Url' => 'https://monday.com/boards/123/pulses/901', 'Nome' => 'Relatório de jornada', 'Responsavel' => 'Ana', 'Status' => 'Pronto para começar', 'Prioridade' => 'Alta', 'Tipo' => 'Funcionalidade', 'Sprint' => null, 'EstH' => 8.0, 'Cliente' => 'YUV'],
            ],
        ], 3600);
        Http::preventStrayRequests();
    }

    public function test_screen_and_maestro_scrum_permissions_guard_the_planning(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::Dashboard)->scrumMaster()->create());
        $this->getJson(route('planning.show'))->assertForbidden();

        $round = PlanningRound::factory()->create();
        $this->actingAs(User::factory()->withScreens(Screen::Planning)->create());
        $this->getJson(route('planning.show'))->assertOk()->assertJsonPath('canConduct', false)->assertJsonPath('hand', null);
        $this->postJson(route('planning.sessions.store'), ['sprint' => 20])->assertForbidden();
        $this->postJson(route('planning.sessions.close', $round->session))->assertForbidden();
        $this->postJson(route('planning.rounds.store', $round->session), ['item' => '901'])->assertForbidden();
        foreach (['planning.rounds.estimate', 'planning.rounds.reveal', 'planning.rounds.cancel'] as $route) {
            $this->postJson(route($route, $round))->assertForbidden();
        }
        $this->postJson(route('planning.rounds.decide', $round), ['source' => 'planning'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_history_lists_the_last_sprints_with_their_tasks_and_planned_and_executed_hours(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::Planning)->create());

        $this->getJson(route('planning.history'))->assertOk()
            ->assertJsonPath('sprints.0.number', 19)
            ->assertJsonPath('sprints.0.cards', 2)
            ->assertJsonPath('sprints.0.plannedHours', 10)
            ->assertJsonPath('sprints.0.executedHours', 12)
            ->assertJsonPath('sprints.0.tasks.0.name', 'Relatório de frota')
            ->assertJsonPath('sprints.0.tasks.0.planned', 8)
            ->assertJsonPath('sprints.0.tasks.0.executed', 10.5)
            ->assertJsonPath('sprints.1.number', 18)
            ->assertJsonCount(2, 'sprints');
    }

    public function test_planning_a_new_sprint_requires_a_current_or_future_sprint_and_one_table_at_a_time(): void
    {
        $maestro = User::factory()->withScreens(Screen::Planning)->scrumMaster()->create(['name' => 'Gustavo']);
        $this->actingAs($maestro)->getJson(route('planning.show'))->assertOk()
            ->assertJsonPath('sprintOptions.0.number', 19)
            ->assertJsonPath('sprintOptions.1.number', 20);

        $this->postJson(route('planning.sessions.store'), [])->assertUnprocessable()->assertJsonValidationErrors(['sprint' => 'Escolha a sprint que vai ser planejada.']);
        $this->postJson(route('planning.sessions.store'), ['sprint' => 18])->assertUnprocessable()->assertJsonPath('error', 'Escolha uma sprint atual ou futura do cronograma.');

        $this->postJson(route('planning.sessions.store'), ['sprint' => 20])->assertOk()
            ->assertJsonPath('session.sprintName', 'Sprint 20')
            ->assertJsonPath('session.startedBy', 'Gustavo')
            ->assertJsonPath('seats.0.name', 'Gustavo')
            ->assertJsonPath('hand.items.0.Id', '901');
        $this->postJson(route('planning.sessions.store'), ['sprint' => 19])->assertUnprocessable()
            ->assertJsonPath('error', 'Já existe uma planning aberta: Sprint 20. Encerre antes de abrir outra.');
    }

    public function test_playing_a_card_puts_it_on_the_table_with_the_ai_estimate_and_the_latest_scope_read_from_monday(): void
    {
        $session = PlanningSession::factory()->create();
        Http::fake(['api.monday.com/v2' => Http::response(['data' => ['items' => [[
            'id' => '901', 'board' => ['id' => '123'], 'column_values' => [['text' => '6']],
            'updates' => [
                ['body' => '<p>Pergunta solta</p>', 'text_body' => 'Pergunta solta', 'created_at' => '2026-11-09T12:00:00Z'],
                ['body' => '<h1 class="x" onclick="alert(1)">Escopo Funcional V2</h1><p>Regra <a href="https://x">link</a></p><script>alert(1)</script><img src="x">', 'text_body' => 'Escopo Funcional V2 Regra', 'created_at' => '2026-11-08T12:00:00Z'],
                ['body' => '<h1>Escopo Funcional V1</h1>', 'text_body' => 'Escopo Funcional V1', 'created_at' => '2026-11-01T12:00:00Z'],
            ],
        ]]]])]);
        $this->actingAs(User::factory()->withScreens(Screen::Planning)->scrumMaster()->create());

        $this->postJson(route('planning.rounds.store', $session), ['item' => '901'])->assertOk()
            ->assertJsonPath('round.item.name', 'Relatório de jornada')
            ->assertJsonPath('round.status', 'reading')
            ->assertJsonPath('round.hasScope', true)
            ->assertJsonPath('round.aiEstimate', null);

        $round = PlanningRound::sole();
        $this->assertSame(6.0, $round->ai_estimate);
        $this->getJson(route('planning.rounds.scope', $round))->assertOk()
            ->assertJsonPath('title', 'Escopo Funcional V2 · publicado em 08/11/2026')
            ->assertJsonPath('html', '<h1>Escopo Funcional V2</h1><p>Regra link</p>');
    }

    public function test_only_backlog_cards_are_played_and_one_card_at_a_time(): void
    {
        $session = PlanningSession::factory()->create();
        $this->actingAs(User::factory()->withScreens(Screen::Planning)->scrumMaster()->create());

        $this->postJson(route('planning.rounds.store', $session), ['item' => '555'])->assertUnprocessable()
            ->assertJsonPath('error', 'Este card não está no Backlog do quadro Tarefas (sem sprint e não iniciado).');

        PlanningRound::factory()->for($session, 'session')->create(['item_name' => 'Outro card']);
        Http::fake(['api.monday.com/v2' => Http::response(['data' => ['items' => [['id' => '901', 'board' => ['id' => '123'], 'column_values' => [['text' => '']], 'updates' => []]]]])]);
        $this->postJson(route('planning.rounds.store', $session), ['item' => '901'])->assertUnprocessable()
            ->assertJsonPath('error', 'O card Outro card ainda está na mesa. Registre a estimativa ou tire o card antes de jogar outro.');
    }

    public function test_cards_are_played_face_down_only_after_the_maestro_opens_the_estimate(): void
    {
        $round = PlanningRound::factory()->create(['ai_estimate' => 8]);
        $ana = User::factory()->withScreens(Screen::Planning)->estimator()->create(['name' => 'Ana']);
        $bruno = User::factory()->withScreens(Screen::Planning)->estimator()->create(['name' => 'Bruno']);

        $this->actingAs($ana)->postJson(route('planning.votes.store', $round), ['hours' => 5])->assertUnprocessable()
            ->assertJsonPath('error', 'Aguarde o Maestro Scrum abrir a estimativa.');

        $this->actingAs(User::factory()->withScreens(Screen::Planning)->scrumMaster()->create())->postJson(route('planning.rounds.estimate', $round))->assertOk()
            ->assertJsonPath('round.status', 'voting');
        $this->actingAs($ana)->getJson(route('planning.show'));
        $this->actingAs($bruno)->getJson(route('planning.show'));
        $this->actingAs($ana)->postJson(route('planning.votes.store', $round), ['hours' => 5])->assertOk();
        $this->actingAs($ana)->postJson(route('planning.votes.store', $round), ['hours' => 6.5])->assertOk()->assertJsonPath('round.myVote', 6.5);

        $seats = collect($this->actingAs($bruno)->getJson(route('planning.show'))->assertOk()
            ->assertJsonPath('round.votes', 1)->assertJsonPath('round.estimators', 2)->assertJsonPath('round.myVote', null)->assertJsonPath('round.aiEstimate', null)
            ->json('seats'))->keyBy('name');
        $this->assertTrue($seats['Ana']['played']);
        $this->assertNull($seats['Ana']['hours']);
        $this->assertFalse($seats['Bruno']['played']);
        $this->assertSame(1, PlanningVote::count());
    }

    public function test_only_the_development_team_plays_cards_not_who_conducts_or_watches(): void
    {
        $round = PlanningRound::factory()->voting()->create();
        $conductor = $round->session->starter;
        $conductor->update(['screens' => [Screen::Planning], 'can_conduct_planning' => true, 'can_estimate_planning' => true]);
        $watcher = User::factory()->withScreens(Screen::Planning)->create(['name' => 'Peterson']);
        $developer = User::factory()->withScreens(Screen::Planning)->estimator()->create(['name' => 'Ana']);

        $this->actingAs($conductor)->postJson(route('planning.votes.store', $round), ['hours' => 5])->assertUnprocessable()
            ->assertJsonPath('error', 'Quem conduz a planning não joga carta: a estimativa é do time.');
        $this->actingAs($watcher)->postJson(route('planning.votes.store', $round), ['hours' => 5])->assertUnprocessable()
            ->assertJsonPath('error', 'Só quem estima na planning joga carta. Peça a permissão "Estima na planning".');
        $this->actingAs($watcher)->getJson(route('planning.show'))->assertOk()->assertJsonPath('canEstimate', false);

        $roles = collect($this->actingAs($developer)->getJson(route('planning.show'))->assertOk()->assertJsonPath('canEstimate', true)
            ->assertJsonPath('round.estimators', 1)->json('seats'))->pluck('role', 'name');
        $this->assertSame('watcher', $roles['Peterson']);
        $this->assertSame('estimator', $roles['Ana']);
        $this->assertSame(0, PlanningVote::count());
    }

    public function test_hours_are_typed_freely_within_the_limits(): void
    {
        $round = PlanningRound::factory()->voting()->create();
        $this->actingAs(User::factory()->withScreens(Screen::Planning)->estimator()->create());

        $this->postJson(route('planning.votes.store', $round), ['hours' => -1])->assertUnprocessable()->assertJsonValidationErrors(['hours' => 'As horas não podem ser negativas.']);
        $this->postJson(route('planning.votes.store', $round), ['hours' => 'muito'])->assertUnprocessable()->assertJsonValidationErrors(['hours' => 'Informe as horas em número.']);
        $this->postJson(route('planning.votes.store', $round), ['hours' => 7.25])->assertOk()->assertJsonPath('round.myVote', 7.25);
    }

    public function test_who_has_no_context_votes_empty_and_zero_counts_as_empty(): void
    {
        $round = PlanningRound::factory()->voting()->create();
        $this->actingAs(User::factory()->withScreens(Screen::Planning)->estimator()->create());

        $this->postJson(route('planning.votes.store', $round), ['hours' => null])->assertOk()->assertJsonPath('round.myVoted', true)->assertJsonPath('round.myVote', null);
        $this->postJson(route('planning.votes.store', $round), ['hours' => 0])->assertOk()->assertJsonPath('round.votes', 1)->assertJsonPath('round.myVote', null);
        $this->assertNull(PlanningVote::sole()->hours);
    }

    public function test_team_average_divides_by_who_pointed_hours_leaving_empty_votes_out(): void
    {
        $round = PlanningRound::factory()->voting()->create(['ai_estimate' => 20]);
        foreach ([8, 12, null, null] as $hours) {
            PlanningVote::factory()->for($round, 'round')->create(['hours' => $hours]);
        }
        $this->actingAs(User::factory()->withScreens(Screen::Planning)->scrumMaster()->create());

        $this->postJson(route('planning.rounds.reveal', $round))->assertOk()
            ->assertJsonPath('round.votes', 4)
            ->assertJsonPath('round.pointed', 2)
            ->assertJsonPath('round.empty', 2)
            ->assertJsonPath('round.sum', 20)
            ->assertJsonPath('round.average', 10);
    }

    public function test_without_any_hours_pointed_there_is_no_team_average_to_choose(): void
    {
        $round = PlanningRound::factory()->voting()->create(['ai_estimate' => 20]);
        PlanningVote::factory()->for($round, 'round')->create(['hours' => null]);
        $this->actingAs(User::factory()->withScreens(Screen::Planning)->scrumMaster()->create());

        $this->postJson(route('planning.rounds.reveal', $round))->assertOk()->assertJsonPath('round.average', null)->assertJsonPath('round.pointed', 0);
        $this->postJson(route('planning.rounds.decide', $round), ['source' => 'planning'])->assertUnprocessable()
            ->assertJsonPath('error', 'Ninguém do time apontou horas: use a estimativa da IA ou tire a carta da mesa.');
        Http::assertNothingSent();
    }

    public function test_reveal_turns_every_card_and_shows_the_team_average_next_to_the_ai_estimate(): void
    {
        $round = PlanningRound::factory()->voting()->create(['ai_estimate' => 8]);
        foreach ([3, 5, 8] as $hours) {
            PlanningVote::factory()->for($round, 'round')->create(['hours' => $hours]);
        }
        $this->actingAs(User::factory()->withScreens(Screen::Planning)->scrumMaster()->create());

        $response = $this->postJson(route('planning.rounds.reveal', $round))->assertOk()
            ->assertJsonPath('round.status', 'revealed')
            ->assertJsonPath('round.sum', 16)
            ->assertJsonPath('round.average', 5.33)
            ->assertJsonPath('round.aiEstimate', 8);
        $this->assertEqualsCanonicalizing([3, 5, 8], collect($response->json('seats'))->pluck('hours')->filter()->values()->all());
    }

    public function test_reveal_needs_at_least_one_card(): void
    {
        $round = PlanningRound::factory()->voting()->create();
        $this->actingAs(User::factory()->withScreens(Screen::Planning)->scrumMaster()->create());

        $this->postJson(route('planning.rounds.reveal', $round))->assertUnprocessable()->assertJsonPath('error', 'Ninguém jogou a carta ainda.');
        $this->assertSame(PlanningStatus::Voting, $round->fresh()->status);
    }

    public function test_choosing_the_team_average_writes_it_to_the_card_estimate_and_comments_the_cards(): void
    {
        $round = PlanningRound::factory()->revealed(5.33)->create(['item_id' => '901', 'ai_estimate' => 8]);
        PlanningVote::factory()->for($round, 'round')->for(User::factory()->create(['name' => 'Bruno']))->create(['hours' => 5]);
        PlanningVote::factory()->for($round, 'round')->for(User::factory()->create(['name' => 'Ana']))->create(['hours' => 3]);
        PlanningVote::factory()->for($round, 'round')->for(User::factory()->create(['name' => 'Carla']))->create(['hours' => 8]);
        PlanningVote::factory()->for($round, 'round')->for(User::factory()->create(['name' => 'Davi']))->create(['hours' => null]);
        $this->fakeMondayWrite();
        $maestro = User::factory()->withScreens(Screen::Planning)->scrumMaster()->create(['name' => 'Gustavo']);

        $this->actingAs($maestro)->postJson(route('planning.rounds.decide', $round), ['source' => 'planning', 'note' => 'O time viu reaproveitamento do bloqueio atual.'])->assertOk()
            ->assertJsonPath('round', null)
            ->assertJsonPath('estimated.0.itemId', '901')
            ->assertJsonPath('estimated.0.finalEstimate', 5.33)
            ->assertJsonPath('estimated.0.finalSource', 'planning');

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'change_multiple_column_values')
            && $request['variables']['item'] === '901' && json_decode($request['variables']['values'], true) === ['est_col' => '5.33']);
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'create_update')
            && str_contains($request['variables']['body'], 'Planning poker da Sprint 20: estimativa definida em 5,33h.')
            && str_contains($request['variables']['body'], 'Decisão do PO (Gustavo): ficou com a média do time (5,33h) em vez da estimativa da IA (8h), 2,67h abaixo.')
            && str_contains($request['variables']['body'], 'Cartas do time: Ana 3h, Bruno 5h, Carla 8h, Davi vazio (sem contexto)')
            && str_contains($request['variables']['body'], 'Média do time: 16h ÷ 3 pessoa(s) que apontaram = 5,33h (1 voto(s) vazio(s) fora da conta)')
            && str_contains($request['variables']['body'], 'O que o time discutiu: O time viu reaproveitamento do bloqueio atual.')
            && str_contains($request['variables']['body'], 'Enviado por Gustavo'));
        $this->assertSame('O time viu reaproveitamento do bloqueio atual.', $round->fresh()->decision_note);
        $this->assertSame(PlanningStatus::Closed, $round->fresh()->status);
        $snapshots = app(MondaySnapshotService::class);
        $snapshots->latest(MondayConnection::current(), 3600);
        $this->assertTrue($snapshots->stale);
    }

    public function test_keeping_the_ai_estimate_writes_the_ai_hours_to_the_card(): void
    {
        $round = PlanningRound::factory()->revealed(5)->create(['item_id' => '901', 'ai_estimate' => 8]);
        PlanningVote::factory()->for($round, 'round')->create(['hours' => 5]);
        $this->fakeMondayWrite();

        $this->actingAs(User::factory()->withScreens(Screen::Planning)->scrumMaster()->create(['name' => 'Gustavo']))
            ->postJson(route('planning.rounds.decide', $round), ['source' => 'ai'])->assertOk()->assertJsonPath('estimated.0.finalEstimate', 8);

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'change_multiple_column_values')
            && json_decode($request['variables']['values'], true) === ['est_col' => '8']);
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'create_update')
            && str_contains($request['variables']['body'], 'Decisão do PO (Gustavo): manteve a estimativa da IA (8h) em vez da média do time (5h), 3h acima.')
            && ! str_contains($request['variables']['body'], 'O que o time discutiu'));
    }

    public function test_ai_estimate_cannot_be_kept_when_the_card_has_none(): void
    {
        $round = PlanningRound::factory()->withoutAiEstimate()->revealed(5)->create();
        $this->actingAs(User::factory()->withScreens(Screen::Planning)->scrumMaster()->create());

        $this->postJson(route('planning.rounds.decide', $round), ['source' => 'ai'])->assertUnprocessable()
            ->assertJsonPath('error', 'O card não tem estimativa da IA: use a média do time.');
        Http::assertNothingSent();
    }

    public function test_card_stays_revealed_on_the_table_when_monday_refuses_the_estimate(): void
    {
        $round = PlanningRound::factory()->revealed(5)->create(['item_id' => '901']);
        Http::fake(['api.monday.com/v2' => Http::response(['errors' => [['message' => 'Sem permissão']]])]);

        $this->actingAs(User::factory()->withScreens(Screen::Planning)->scrumMaster()->create())
            ->postJson(route('planning.rounds.decide', $round), ['source' => 'planning'])->assertUnprocessable();

        $this->assertSame(PlanningStatus::Revealed, $round->fresh()->status);
        $this->assertNull($round->fresh()->final_estimate);
    }

    public function test_closing_the_table_takes_the_open_card_out_without_touching_monday(): void
    {
        $round = PlanningRound::factory()->voting()->create();
        $this->actingAs(User::factory()->withScreens(Screen::Planning)->scrumMaster()->create());

        $this->postJson(route('planning.sessions.close', $round->session))->assertOk()->assertJsonPath('session', null);

        $this->assertSame(PlanningStatus::Canceled, $round->fresh()->status);
        $this->assertSame(PlanningSession::CLOSED, $round->session->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_seats_show_who_has_the_table_open_recently(): void
    {
        $session = PlanningSession::factory()->create();
        $ana = User::factory()->withScreens(Screen::Planning)->create(['name' => 'Ana']);
        $this->actingAs($ana)->getJson(route('planning.show'))->assertOk();

        $this->travel(PlanningService::PRESENCE_SECONDS + 5)->seconds();
        $bruno = User::factory()->withScreens(Screen::Planning)->create(['name' => 'Bruno']);
        $this->actingAs($bruno)->getJson(route('planning.show'))->assertOk()->assertJsonPath('seats', [
            ['id' => $bruno->id, 'name' => 'Bruno', 'isMe' => true, 'role' => 'watcher', 'played' => false, 'hours' => null],
        ]);
        $this->assertSame(2, $session->participants()->count());
    }

    public function test_team_load_adds_the_sprint_cards_and_the_cards_estimated_in_this_planning_against_each_capacity(): void
    {
        $connection = MondayConnection::current();
        $connection->capacity_overrides = ['20|Bruno' => 40 * 60];
        $connection->metric_settings = ['capacityHours' => 80, 'excludedPeople' => ['Gestor']];
        $connection->save();
        $snapshot = Cache::get(MondaySnapshotService::CACHE_KEY);
        $snapshot['rows'] = [
            ['Id' => '30', 'Nome' => 'Já na sprint', 'Status' => 'Pronto para começar', 'Responsavel' => 'Ana', 'Sprint' => 20, 'EstH' => 30.0],
            ['Id' => '31', 'Nome' => 'Em dupla', 'Status' => 'Pronto para começar', 'Responsavel' => 'Ana, Bruno', 'Sprint' => 20, 'EstH' => 10.0],
            ['Id' => '32', 'Nome' => 'Cancelado', 'Status' => 'Cancelado', 'Responsavel' => 'Ana', 'Sprint' => 20, 'EstH' => 50.0],
            ['Id' => '33', 'Nome' => 'Outra sprint', 'Status' => 'Implementado', 'Responsavel' => 'Ana', 'Sprint' => 19, 'EstH' => 99.0],
            ['Id' => '34', 'Nome' => 'Do gestor', 'Status' => 'Backlog', 'Responsavel' => 'Gestor', 'Sprint' => 20, 'EstH' => 9.0],
        ];
        Cache::put(MondaySnapshotService::CACHE_KEY, $snapshot, 3600);
        $session = PlanningSession::factory()->create();
        PlanningRound::factory()->for($session, 'session')->create(['status' => PlanningStatus::Closed, 'item_id' => '901', 'item_owner' => 'Bruno', 'final_estimate' => 38, 'final_source' => 'planning']);
        PlanningRound::factory()->for($session, 'session')->create(['status' => PlanningStatus::Closed, 'item_id' => '30', 'item_owner' => 'Ana', 'final_estimate' => 30, 'final_source' => 'planning']);

        $load = collect($this->actingAs(User::factory()->withScreens(Screen::Planning)->create())->getJson(route('planning.show'))->assertOk()->json('load'))->keyBy('name');

        $this->assertSame(['Bruno', 'Ana'], $load->keys()->all());
        $this->assertEquals(['name' => 'Bruno', 'inSprint' => 5, 'planned' => 38, 'total' => 43, 'capacity' => 40, 'percent' => 108], $load['Bruno']);
        $this->assertEquals(['name' => 'Ana', 'inSprint' => 35, 'planned' => 0, 'total' => 35, 'capacity' => 80, 'percent' => 44], $load['Ana']);
    }

    public function test_backlog_of_the_snapshot_has_only_cards_without_sprint_not_started_and_not_support(): void
    {
        $item = fn (string $id, string $status, string $sprint, string $type): array => ['id' => $id, 'name' => "Card {$id}", 'url' => '', 'column_values' => [
            ['id' => 'task_status', 'text' => $status, 'type' => 'status'],
            ['id' => 'task_sprint', 'text' => $sprint, 'type' => 'board_relation', 'display_value' => $sprint],
            ['id' => 'task_type', 'text' => $type, 'type' => 'status'],
            ['id' => 'est_col', 'text' => '5', 'type' => 'numbers'],
        ]];

        $converted = app(MondayService::class)->convertItems([
            $item('1', 'Pronto para começar', '', 'Funcionalidade'),
            $item('2', 'Em progresso', '', 'Funcionalidade'),
            $item('3', 'Pronto para começar', 'Sprint 19', 'Funcionalidade'),
            $item('4', 'Backlog', '', 'Suporte'),
        ], MondayConnection::current()->column_map);

        $this->assertSame(['1'], array_column($converted['backlog'], 'Id'));
        $this->assertSame(5.0, $converted['backlog'][0]['EstH']);
    }

    public function test_maestro_scrum_permission_is_set_in_the_user_registration(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::Users)->create());

        $this->postJson('/api/users', [
            'name' => 'Paula', 'email' => 'paula@yuv.com.br', 'password' => 'senha-segura', 'screens' => ['planning'], 'can_conduct_planning' => true, 'can_estimate_planning' => true,
        ])->assertCreated()->assertJsonPath('user.canConductPlanning', true)->assertJsonPath('user.canEstimatePlanning', true);

        $paula = User::where('email', 'paula@yuv.com.br')->sole();
        $this->assertTrue($paula->canConductPlanning());
        $this->assertTrue($paula->canEstimatePlanning());
    }

    /** Respostas do Monday para gravar o Tempo estimado e publicar o comentário do card 901. */
    private function fakeMondayWrite(): void
    {
        Http::fakeSequence('api.monday.com/v2')
            ->push(['data' => ['items' => [['id' => '901', 'board' => ['id' => '123', 'columns' => [['id' => 'est_col', 'title' => 'Tempo Estimado', 'type' => 'numbers', 'settings_str' => '{}']]]]]]])
            ->push(['data' => ['change_multiple_column_values' => ['id' => '901']]])
            ->push(['data' => ['items' => [['id' => '901', 'board' => ['id' => '123']]]]])
            ->push(['data' => ['create_update' => ['id' => '777']]]);
    }
}
