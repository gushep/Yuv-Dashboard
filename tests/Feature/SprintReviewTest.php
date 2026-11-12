<?php

namespace Tests\Feature;

use App\Models\MondayConnection;
use App\Models\SprintReview;
use App\Models\SprintReviewFeedback;
use App\Models\User;
use App\Screen;
use App\Services\MondaySnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SprintReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00', 'America/Sao_Paulo'));
        $connection = MondayConnection::current();
        $connection->board_id = '123';
        $connection->token = 'token-secreto';
        $connection->roadmap_board_id = '777';
        $connection->column_map = ['Sprint' => 'task_sprint', 'Status' => 'task_status'];
        $connection->save();
        $row = fn (string $id, string $name, string $status, string $type, string $planned, string $epic = '') => ['Id' => $id, 'Url' => "https://monday.com/boards/123/pulses/{$id}", 'Nome' => $name, 'Status' => $status, 'Tipo' => $type, 'Planejada' => $planned, 'Epico' => $epic, 'Responsavel' => 'Igor', 'Sprint' => 19, 'EstH' => 4.0, 'RealH' => 3.0, 'Descricao' => 'Escopo combinado.'];
        Cache::forever(MondaySnapshotService::CACHE_KEY, [
            'syncedAt' => now()->toIso8601String(),
            'rows' => [
                $row('901', 'Discovery das câmeras', 'Implementado', 'Funcionalidade', 'Planejada', 'Datavision'),
                $row('902', 'Relatório de velocidade', 'Em progresso', 'Melhoria', 'Planejada'),
                $row('903', 'JC400AD duração do vídeo', 'Implementado', 'Suporte', 'Não Planejado'),
                $row('904', 'Card cancelado', 'Cancelado', 'Melhoria', 'Planejada'),
                $row('905', 'Ticket aberto', 'Backlog', 'Suporte', 'Não Planejado'),
            ],
            'sprints' => [
                ['number' => 19, 'name' => 'Sprint 19', 'start' => '2026-10-27', 'end' => '2026-11-10', 'source' => '', 'completed' => true],
                ['number' => 20, 'name' => 'Sprint 20', 'start' => '2026-11-10', 'end' => '2026-11-24', 'source' => '', 'completed' => false],
            ],
            'sprintItems' => [],
        ]);
        Http::preventStrayRequests();
    }

    private function master(array $attributes = []): User
    {
        return User::factory()->withScreens(Screen::SprintReview)->scrumMaster()->create($attributes);
    }

    private function member(array $attributes = []): User
    {
        return User::factory()->withScreens(Screen::SprintReview)->create($attributes);
    }

    public function test_the_review_requires_its_screen(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::Dashboard)->create());
        $this->getJson(route('review.show'))->assertForbidden();

        $this->actingAs($this->member());
        $this->getJson(route('review.show'))->assertOk()->assertJsonPath('canFacilitate', false)
            ->assertJsonPath('sprints.0.number', 20)->assertJsonPath('sprints.0.startable', false)
            ->assertJsonPath('sprints.1.startable', true);
    }

    public function test_it_starts_on_the_last_day_of_the_sprint_or_earlier_when_closed_on_monday(): void
    {
        $this->actingAs($this->member());
        $this->postJson(route('review.start'), ['sprint' => 19])->assertUnprocessable()->assertJsonPath('error', 'Só o Maestro Scrum pode iniciar a Sprint Review.');

        $this->actingAs($this->master(['name' => 'Gustavo']));
        $this->postJson(route('review.start'), ['sprint' => 20])->assertUnprocessable()
            ->assertJsonPath('error', 'A review da Sprint 20 abre no último dia da sprint (24/11) ou quando ela for fechada no Monday.');
        // Sprint 19 já foi fechada no Monday: pode começar.
        $this->postJson(route('review.start'), ['sprint' => 19])->assertOk()->assertJsonPath('review.sprint', 19)->assertJsonPath('review.facilitator.name', 'Gustavo');

        // No último dia da Sprint 20 ela também pode começar (depois de encerrar a da 19).
        $this->postJson(route('review.close', SprintReview::sole()), ['metrics' => []])->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-11-24 15:00', 'America/Sao_Paulo'));
        $this->postJson(route('review.start'), ['sprint' => 20])->assertOk()->assertJsonPath('review.sprint', 20);
        $this->postJson(route('review.start'), ['sprint' => 19])->assertUnprocessable()->assertJsonPath('error', 'Encerre a review da Sprint 20 antes de iniciar outra.');
    }

    public function test_the_demonstration_lists_the_deliveries_and_the_planned_cards_left_open(): void
    {
        SprintReview::factory()->create(['sprint' => 19]);
        $this->actingAs($this->member());

        $items = $this->getJson(route('review.room'))->assertOk()->json('review.items');
        $this->assertSame([['901', 'delivered'], ['903', 'delivered'], ['902', 'pending']], array_map(fn (array $item): array => [$item['id'], $item['kind']], $items));
        $this->assertSame(['Datavision', 'Escopo combinado.'], [$items[0]['epic'], $items[0]['description']]);
    }

    public function test_only_the_scrum_master_marks_the_acceptance_and_drives_the_stage(): void
    {
        $review = SprintReview::factory()->create(['sprint' => 19]);
        $this->actingAs($this->member());
        $this->postJson(route('review.decide', $review), ['item_id' => '901', 'verdict' => 'accepted'])->assertUnprocessable()
            ->assertJsonPath('error', 'Só o Maestro Scrum conduz a review e marca o aceite das entregas.');

        $this->actingAs($this->master());
        $this->postJson(route('review.focus', $review), ['item_id' => '901'])->assertOk()->assertJsonPath('review.currentItemId', '901');
        $this->postJson(route('review.decide', $review), ['item_id' => '901', 'verdict' => 'adjust', 'notes' => 'Filtro por cliente'])->assertOk()
            ->assertJsonPath('review.items.0.verdict', 'adjust')->assertJsonPath('review.items.0.notes', 'Filtro por cliente');
        $this->postJson(route('review.decide', $review), ['item_id' => '902', 'reason' => 'capacity', 'destination' => 'next'])->assertOk()
            ->assertJsonPath('review.items.2.reason', 'capacity')->assertJsonPath('review.items.2.destination', 'next');
        $this->postJson(route('review.decide', $review), ['item_id' => '905', 'verdict' => 'accepted'])->assertUnprocessable()
            ->assertJsonPath('error', 'Este card não é uma entrega nem um planejado da sprint.');
        $this->postJson(route('review.goal', $review), ['result' => 'partial', 'note' => 'Faltou o relatório'])->assertOk()->assertJsonPath('review.goalResult', 'partial');
        Http::assertNothingSent();
    }

    public function test_feedback_becomes_a_demand_in_the_funnel_only_on_request_and_only_once(): void
    {
        $review = SprintReview::factory()->create(['sprint' => 19]);
        $marina = $this->member(['name' => 'Marina']);
        $this->actingAs($this->member(['name' => 'Ana']));
        $this->postJson(route('review.feedbacks.store', $review), ['text' => 'Filtro por cliente na lista de câmeras', 'type' => 'improvement', 'given_by' => $marina->id, 'item_id' => '901'])->assertOk()
            ->assertJsonPath('review.feedbacks.0.givenBy', 'Marina')
            ->assertJsonPath('review.feedbacks.0.item.name', 'Discovery das câmeras')
            ->assertJsonPath('review.feedbacks.0.demand.status', null);
        $feedback = SprintReviewFeedback::sole();
        Http::assertNothingSent();

        // Sem a permissão "Atualizar cards" não cria.
        $this->postJson(route('review.feedbacks.demand', $feedback))->assertUnprocessable()->assertJsonPath('error', 'Criar demanda no Monday exige a permissão "Atualizar cards".');

        Http::fakeSequence('api.monday.com/v2')->push($this->funnelColumns())->push(['data' => ['create_item' => ['id' => '5501', 'url' => 'https://monday.com/boards/777/pulses/5501']]]);
        $this->actingAs(User::factory()->withScreens(Screen::SprintReview)->canUpdateItems()->create(['name' => 'Gustavo']));
        $this->postJson(route('review.feedbacks.demand', $feedback))->assertOk()
            ->assertJsonPath('review.feedbacks.0.demand.status', 'created')
            ->assertJsonPath('review.feedbacks.0.demand.id', '5501');
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'create_item') && $request['variables']['board'] === '777'
            && $request['variables']['name'] === 'Filtro por cliente na lista de câmeras'
            && str_contains($request['variables']['values'], 'Marina')
            && str_contains(json_decode($request['variables']['values'], true)['long_desc']['text'], 'Entrega: Discovery das câmeras'));
        $this->postJson(route('review.feedbacks.demand', $feedback))->assertUnprocessable()->assertJsonPath('error', 'Este feedback já virou demanda no Funil de Demandas.');
        $this->postJson(route('review.feedbacks.delete', $feedback))->assertUnprocessable();
    }

    public function test_a_monday_failure_keeps_the_feedback_and_the_demand_can_be_retried(): void
    {
        $review = SprintReview::factory()->create(['sprint' => 19]);
        $feedback = $review->feedbacks()->create(['type' => 'idea', 'text' => 'Mapa por região']);
        Http::fakeSequence('api.monday.com/v2')->push(['errors' => [['message' => 'Sem permissão']]])
            ->push($this->funnelColumns())->push(['data' => ['create_item' => ['id' => '5502', 'url' => '']]]);
        $this->actingAs(User::factory()->withScreens(Screen::SprintReview)->canUpdateItems()->create());

        $this->postJson(route('review.feedbacks.demand', $feedback))->assertOk()->assertJsonPath('review.feedbacks.0.demand.status', 'failed');
        $this->postJson(route('review.feedbacks.demand', $feedback))->assertOk()->assertJsonPath('review.feedbacks.0.demand.status', 'created');
    }

    public function test_closing_freezes_the_result_and_fills_the_history(): void
    {
        $review = SprintReview::factory()->create(['sprint' => 19, 'goal_result' => 'yes']);
        $review->decisions()->create(['item_id' => '901', 'verdict' => 'accepted']);
        $review->decisions()->create(['item_id' => '903', 'verdict' => 'rejected']);
        $this->actingAs($master = $this->master());
        $this->getJson(route('review.room'))->assertOk();

        $this->postJson(route('review.close', $review), ['metrics' => ['delivered' => 2]])->assertOk()->assertJsonPath('review', null);
        $this->assertSame(['901', '903', '902'], array_column($review->fresh()->items, 'id'));
        $this->getJson(route('review.show'))->assertOk()
            ->assertJsonPath('kpis.reviews', 1)->assertJsonPath('kpis.accepted', 1)->assertJsonPath('kpis.presented', 2)->assertJsonPath('kpis.rate', 50)
            ->assertJsonPath('kpis.goalHit', 1)
            ->assertJsonPath('sprints.1.review.status', 'closed')
            ->assertJsonPath('sprints.1.review.delivered', 2)
            ->assertJsonPath('sprints.1.review.attendees', [$master->name]);
        $this->getJson(route('review.sprint', 19))->assertOk()->assertJsonPath('review.canConduct', false)->assertJsonPath('review.items.0.verdict', 'accepted');
        // A etapa Dados da retro mostra o resultado da review.
        $this->actingAs(User::factory()->withScreens(Screen::SprintRetrospective)->create())
            ->getJson(route('retro.review', 19))->assertOk()->assertJsonPath('review.verdicts.accepted', 1)->assertJsonPath('review.goalResult', 'yes');
    }

    /** Colunas do Funil de Demandas reconhecidas pelo título (as mesmas do Novo Ticket de melhoria). */
    private function funnelColumns(): array
    {
        $status = fn (array $labels): string => json_encode(['labels' => $labels], JSON_UNESCAPED_UNICODE);
        $column = fn (string $id, string $title, string $type, string $settings = '{}') => ['id' => $id, 'title' => $title, 'type' => $type, 'settings_str' => $settings];

        return ['data' => ['boards' => [['name' => 'Funil de Demandas', 'columns' => [
            $column('text_req', 'Nome solicitante', 'text'), $column('text_client', 'Cliente', 'text'),
            $column('st_origin', 'Origem', 'status', $status(['1' => 'Cliente'])), $column('st_type', 'Tipo', 'status', $status(['1' => 'Melhoria'])),
            $column('st_module', 'Módulo', 'status', $status(['1' => 'Plataforma'])), $column('long_desc', 'Descrição da demanda', 'long_text'),
            $column('drop_affected', 'Quem é afetado?', 'dropdown', '{"labels":[{"id":1,"name":"Cliente"}]}'), $column('long_solution', 'Solução proposta', 'long_text'),
            $column('file_evidence', 'Evidências', 'file'), $column('long_req', 'Requisitos', 'long_text'),
            $column('st_motivation', 'Motivação da demanda para o negócio', 'status', $status(['1' => 'Retenção'])), $column('st_mrr', 'Possível MRR', 'status', $status(['1' => 'Baixo'])),
            $column('st_severity', 'Severidade', 'status', $status(['1' => 'Alta'])), $column('st_status', 'Status', 'status', $status(['0' => 'Novo'])),
        ]]]]];
    }
}
