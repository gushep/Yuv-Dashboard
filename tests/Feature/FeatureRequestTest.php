<?php

namespace Tests\Feature;

use App\Models\MondayConnection;
use App\Models\User;
use App\Screen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FeatureRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = MondayConnection::current();
        $connection->board_id = '123';
        $connection->roadmap_board_id = '999';
        $connection->token = 'token-secreto';
        $connection->save();
        Http::preventStrayRequests();
    }

    public function test_opening_requests_requires_the_ticket_permission(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::RawData)->create());
        $this->getJson('/api/feature-requests/options')->assertForbidden();
        $this->postJson('/api/feature-requests', $this->request())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_options_come_from_the_funnel_board_without_bug_as_type(): void
    {
        Http::fake(['api.monday.com/v2' => Http::response($this->boardResponse())]);

        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canCreateTickets()->create(['name' => 'Ana Produto']))
            ->getJson('/api/feature-requests/options')->assertOk()
            ->assertJsonPath('boardName', 'Funil de Demandas')
            ->assertJsonPath('requester', 'Ana Produto')
            ->assertJsonPath('type', ['Feature', 'Melhoria', 'Homologação'])
            ->assertJsonPath('affected', ['Base Toda', 'Revendedores', 'Motoristas'])
            ->assertJsonPath('severity', ['Alta', 'Crítico', 'Média', 'Baixa']);
    }

    public function test_request_is_created_in_the_funnel_awaiting_review_with_every_form_column(): void
    {
        $this->travelTo('2026-10-27 10:00:00');
        Http::fakeSequence('api.monday.com/v2')
            ->push($this->boardResponse())
            ->push(['data' => ['create_item' => ['id' => '701', 'url' => 'https://monday.com/boards/999/pulses/701']]]);

        $this->actingAs(User::factory()->withScreens(Screen::Firefighter)->canCreateTickets()->create())
            ->postJson('/api/feature-requests', $this->request())->assertOk()
            ->assertJsonPath('id', '701')->assertJsonPath('boardName', 'Funil de Demandas');

        Http::assertSent(function (Request $request): bool {
            if (! str_starts_with((string) $request['query'], 'mutation')) {
                return false;
            }
            $this->assertSame(['board' => '999', 'name' => 'Filtro por modelo de equipamento'], array_intersect_key($request['variables'], ['board' => 1, 'name' => 1]));
            $this->assertEqualsCanonicalizing([
                'text_req' => 'Joana CS',
                'long_desc' => ['text' => 'Clientes não conseguem filtrar alarmes por modelo.'],
                'long_sol' => ['text' => 'Adicionar o filtro na tela de alarmes.'],
                'long_req' => ['text' => 'Filtrar por um ou mais modelos.'],
                'drop_aff' => ['labels' => ['Base Toda', 'Motoristas']],
                'status' => ['label' => 'Aguardando revisão'],
                'origin' => ['label' => 'CS'],
                'type' => ['label' => 'Melhoria'],
                'module' => ['label' => 'Relatorios'],
                'motivation' => ['label' => 'Retenção'],
                'mrr' => ['label' => 'Sem receita direta'],
                'impact' => ['label' => 'Média'],
                'date' => ['date' => '2026-10-27'],
            ], json_decode($request['variables']['values'], true));

            return true;
        });
    }

    public function test_solution_requirements_and_mrr_are_optional_and_left_blank_in_the_funnel(): void
    {
        Http::fakeSequence('api.monday.com/v2')
            ->push($this->boardResponse())
            ->push(['data' => ['create_item' => ['id' => '702', 'url' => '']]]);

        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canCreateTickets()->create())
            ->postJson('/api/feature-requests', $this->request(['solution' => null, 'requirements' => '', 'mrr' => null]))->assertOk();

        Http::assertSent(fn (Request $request): bool => str_starts_with((string) $request['query'], 'mutation')
            && array_intersect_key(json_decode($request['variables']['values'], true), array_flip(['long_sol', 'long_req', 'mrr'])) === []);
    }

    public function test_required_fields_and_choices_must_exist_in_the_funnel(): void
    {
        Http::fake(['api.monday.com/v2' => Http::response($this->boardResponse())]);
        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canCreateTickets()->create());

        $this->postJson('/api/feature-requests', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'requester', 'origin', 'type', 'module', 'description', 'affected', 'motivation', 'severity'])
            ->assertJsonMissingValidationErrors(['solution', 'requirements', 'mrr']);
        $this->postJson('/api/feature-requests', $this->request(['mrr' => 'Milhões']))
            ->assertUnprocessable()->assertJsonPath('error', 'Possível MRR inválido(a) para o Funil de Demandas.');
        $this->postJson('/api/feature-requests', $this->request(['type' => 'Bug']))
            ->assertUnprocessable()->assertJsonPath('error', 'Tipo inválido(a) para o Funil de Demandas.');
        $this->postJson('/api/feature-requests', $this->request(['affected' => ['Todo mundo']]))
            ->assertUnprocessable()->assertJsonPath('error', 'Quem é afetado tem uma opção que não existe no Funil de Demandas.');
        Http::assertNotSent(fn (Request $request): bool => str_starts_with((string) $request['query'], 'mutation'));
    }

    public function test_without_the_roadmap_board_configured_the_request_is_refused(): void
    {
        $connection = MondayConnection::current();
        $connection->roadmap_board_id = null;
        $connection->save();

        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canCreateTickets()->create())
            ->getJson('/api/feature-requests/options')->assertUnprocessable()
            ->assertJsonPath('error', 'Configure o quadro do Roadmap (Funil de Demandas) nas Configurações antes de abrir demandas.');
        Http::assertNothingSent();
    }

    public function test_evidence_goes_to_the_evidence_column_only_for_requests_opened_in_this_session(): void
    {
        Http::fakeSequence('api.monday.com/v2/file')->push(['data' => ['add_file_to_column' => ['id' => '8001']]]);
        Http::fakeSequence('api.monday.com/v2')
            ->push($this->boardResponse())
            ->push(['data' => ['create_item' => ['id' => '701', 'url' => '']]])
            ->push($this->boardResponse())
            ->push(['data' => ['items' => [['id' => '701', 'board' => ['id' => '999']]]]]);
        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canCreateTickets()->create());
        $upload = fn (string $item) => $this->post("/api/feature-requests/{$item}/files",
            ['file' => UploadedFile::fake()->image('print-alarmes.png')], ['Accept' => 'application/json']);

        $upload('701')->assertForbidden();
        $this->postJson('/api/feature-requests', $this->request())->assertOk();
        $upload('555')->assertForbidden();
        $upload('701')->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.monday.com/v2/file'
            && $request->hasFile('variables[file]', null, 'print-alarmes.png')
            && str_contains((string) collect($request->data())->firstWhere('name', 'query')['contents'], 'add_file_to_column(item_id: 701, column_id: "file_ev"'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function request(array $overrides = []): array
    {
        return [
            'title' => 'Filtro por modelo de equipamento', 'requester' => ' Joana CS ', 'origin' => 'CS', 'type' => 'Melhoria', 'module' => 'Relatorios',
            'description' => 'Clientes não conseguem filtrar alarmes por modelo.', 'affected' => ['Base Toda', 'Motoristas'],
            'solution' => 'Adicionar o filtro na tela de alarmes.', 'requirements' => 'Filtrar por um ou mais modelos.',
            'motivation' => 'Retenção', 'mrr' => 'Sem receita direta', 'severity' => 'Média', ...$overrides,
        ];
    }

    /**
     * Funil de Demandas com as colunas do formulário de demandas (Quem é afetado? existe como status antigo e como dropdown).
     *
     * @return array<string, mixed>
     */
    private function boardResponse(): array
    {
        $status = fn (string $id, string $title, array $labels): array => ['id' => $id, 'title' => $title, 'type' => 'status', 'settings_str' => json_encode(['labels' => $labels])];

        return ['data' => ['boards' => [[
            'name' => 'Funil de Demandas',
            'columns' => [
                ['id' => 'name', 'title' => 'Titulo', 'type' => 'name', 'settings_str' => '{}'],
                $status('impact', 'Impacto', ['0' => 'Alta', '1' => 'Crítico', '2' => 'Média', '7' => 'Baixa']),
                ['id' => 'long_desc', 'title' => 'Descrição da demanda', 'type' => 'long_text', 'settings_str' => '{}'],
                $status('status', 'Status', ['1' => 'Implementado', '9' => 'Aguardando revisão']),
                $status('origin', 'Origem', ['0' => 'Interno', '2' => 'CS']),
                $status('module', 'Modulo', ['1' => 'Rastreamento', '5' => 'Relatorios']),
                ['id' => 'text_req', 'title' => 'Nome solicitante', 'type' => 'text', 'settings_str' => '{}'],
                $status('type', 'Tipo', ['0' => 'Feature', '1' => 'Melhoria', '2' => 'Bug', '4' => 'Homologação']),
                ['id' => 'date', 'title' => 'Data do registro', 'type' => 'date', 'settings_str' => '{}'],
                ['id' => 'long_sol', 'title' => 'Solução proposta', 'type' => 'long_text', 'settings_str' => '{}'],
                ['id' => 'long_req', 'title' => 'Requisitos', 'type' => 'long_text', 'settings_str' => '{}'],
                $status('old_aff', 'Quem é afetado?', ['0' => 'Base inteira']),
                ['id' => 'drop_aff', 'title' => 'Quem é afetado?', 'type' => 'dropdown', 'settings_str' => json_encode(['labels' => [['id' => 0, 'name' => 'Base Toda'], ['id' => 1, 'name' => 'Revendedores'], ['id' => 3, 'name' => 'Motoristas']]])],
                ['id' => 'file_ev', 'title' => 'Evidências', 'type' => 'file', 'settings_str' => '{}'],
                $status('motivation', 'Motivação da demanda para o negócio', ['0' => 'Novos Clientes', '2' => 'Retenção']),
                $status('mrr', 'Possível MRR', ['0' => 'Até R$1K', '4' => 'Sem receita direta']),
            ],
        ]]]];
    }
}
