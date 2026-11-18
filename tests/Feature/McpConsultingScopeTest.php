<?php

namespace Tests\Feature;

use App\Mcp\Prompts\ConsultingScopePrompt;
use App\Mcp\Tools\MondayCardTool;
use App\Mcp\Tools\PublishScopeTool;
use App\Mcp\Tools\TaskHistoryTool;
use App\Models\MondayConnection;
use App\Models\User;
use App\Screen;
use App\Services\MondaySnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server;
use Mockery\MockInterface;
use Tests\TestCase;

class McpConsultingScopeTest extends TestCase
{
    use RefreshDatabase;

    private const BACKLOG_STATUS = '{"labels":{"0":"Adicionado ao Backlog","1":"Ag. Validação"}}';

    private const FUNNEL_STATUS = '{"labels":{"0":"Novo","1":"Aguardando Validação"}}';

    /** @var array<string, array<string, mixed>> */
    private array $items = [];

    /** @var list<string> */
    private array $linked = [];

    /** @var list<array{id: string, name: string}> */
    private array $funnelSearch = [];

    private array $extraUpdates = [];

    private bool $failStatusChange = false;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = MondayConnection::current();
        $connection->forceFill([
            'board_id' => '123', 'backlog_board_id' => '456', 'roadmap_board_id' => '789', 'token' => 'token-secreto',
            'column_map' => ['Sprint' => 'sprint', 'Status' => 'status', 'EstH' => 'numeric_mm3hjsna', 'Responsavel' => 'task_owner'],
        ])->save();
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->failStatusChange && str_contains((string) $request['query'], 'change_simple_column_value')
            ? Http::response(['errors' => [['message' => 'Monday indisponível']]])
            : Http::response(['data' => $this->monday((string) $request['query'], (array) ($request['variables'] ?? []))]));
    }

    public function test_the_prompt_delivers_the_instructions_the_method_the_model_and_the_platform_reference(): void
    {
        ConsultingTestServer::prompt(ConsultingScopePrompt::class, ['card' => '55'])->assertOk()->assertSee([
            'Card do Monday: 55',
            'Sem levantamento técnico no código',
            'Checklist final de qualidade',
            'Alerta de Equipamento Sem Comunicação',
            'Referência da plataforma YUV',
        ]);
    }

    public function test_monday_card_returns_the_card_with_comments_and_the_linked_funnel_demand(): void
    {
        $this->items['55'] = $this->item('55', '456', 'Playback concatenado', updates: [$this->update('901', '<p>Contexto PM: juntar vídeos</p>')]);
        $this->items['3001'] = $this->item('3001', '789', 'Demanda de origem do playback', status: 'Novo');
        $this->linked = ['3001'];

        ConsultingTestServer::actingAs($this->user())->tool(MondayCardTool::class, ['item_id' => '55'])->assertOk()
            ->assertSee(['"quadro":"backlog"', 'Playback concatenado', 'Contexto PM: juntar vídeos', 'Demanda de origem do playback']);
    }

    public function test_monday_card_without_a_link_finds_the_funnel_demand_by_name(): void
    {
        $this->items['55'] = $this->item('55', '456', 'Homologação N9 PRO (copy)');
        $this->items['3002'] = $this->item('3002', '789', 'Homologação N9 PRO', status: 'Novo');
        $this->funnelSearch = [['id' => '3002', 'name' => 'Homologação N9 PRO']];

        ConsultingTestServer::actingAs($this->user())->tool(MondayCardTool::class, ['item_id' => '55'])->assertOk()->assertSee('"id":"3002"');
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'items_page') && ($request['variables']['name'] ?? null) === 'Homologação N9 PRO');
    }

    public function test_task_history_filters_by_domain_terms_and_minimum_id_keeping_one_row_per_card(): void
    {
        $rows = [
            ['Id' => '13400000001', 'Nome' => 'Playback Schedule', 'Tipo' => 'Melhoria', 'Responsavel' => 'Rafael', 'EstH' => 15, 'RealH' => 12, 'Status' => 'Implementado', 'Sprint' => 18],
            ['Id' => '13400000001', 'Nome' => 'Playback Schedule', 'Sprint' => 17, 'AsOf' => '2026-10-01'],
            ['Id' => '13100000000', 'Nome' => 'Playback antigo', 'Tipo' => 'Bug', 'Responsavel' => 'Ana', 'EstH' => 2, 'RealH' => 3, 'Status' => 'Implementado', 'Sprint' => 9],
            ['Id' => '13500000000', 'Nome' => 'Vídeo ao vivo instável', 'Tipo' => 'Bug', 'Responsavel' => 'Cássio', 'EstH' => 4, 'RealH' => 5.5, 'Status' => 'Implementado', 'Sprint' => 19],
            ['Id' => '13500000001', 'Nome' => 'Cadastro de motorista', 'Tipo' => 'Melhoria', 'Responsavel' => 'Gabriel', 'EstH' => 3, 'RealH' => 2, 'Status' => 'Implementado', 'Sprint' => 19],
        ];
        $this->mock(MondaySnapshotService::class, function (MockInterface $mock) use ($rows): void {
            $mock->shouldReceive('latest')->andReturn(['rows' => $rows, 'sprints' => [], 'syncedAt' => '2026-11-18T10:00:00Z']);
            $mock->shouldNotReceive('withFrozenSprints');
        });

        ConsultingTestServer::actingAs($this->user())->tool(TaskHistoryTool::class, ['search' => 'playback|video', 'min_id' => 13150000000])->assertOk()
            ->assertSee(['"total":2', '"nome":"Vídeo ao vivo instável"', '"horasReais":5.5', '"nome":"Playback Schedule"', '"sprint":18'])
            ->assertDontSee(['Playback antigo', 'Cadastro de motorista']);
    }

    public function test_publishing_without_the_update_cards_permission_is_refused_without_calling_monday(): void
    {
        $user = User::factory()->withScreens(Screen::RawData)->create();

        ConsultingTestServer::actingAs($user)->tool(PublishScopeTool::class, ['item_id' => '55', 'board' => 'backlog', 'scope' => '# Escopo'])
            ->assertHasErrors(['Publicar escopos exige a permissão "Atualizar cards"']);
        Http::assertNothingSent();
    }

    public function test_publishing_creates_the_signed_comment_moves_to_validation_and_fills_empty_columns(): void
    {
        $this->items['55'] = $this->item('55', '456', 'Playback concatenado');

        ConsultingTestServer::actingAs($this->user())->tool(PublishScopeTool::class, [
            'item_id' => '55', 'board' => 'backlog', 'hours' => 20, 'responsible' => 'Rafael Souza da Silva',
            'scope' => "# [YUV - Vídeo] – Concatenação\n\n## 🧪 16. Critérios de Aceite\n\n- [ ] Gerar o vídeo <concatenado>\n\n| Fluxo | Resultado |\n|---|---|\n| Download | Individual |",
        ])->assertOk()->assertSee(['"acao":"criado"', 'alterado para Ag. Validação', 'preenchida (20h)', 'preenchido (Rafael Souza da Silva)']);

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'create_update')
            && str_contains($request['variables']['body'], '<h1>[YUV - Vídeo] – Concatenação</h1>')
            && str_contains($request['variables']['body'], '<li>☐ Gerar o vídeo &lt;concatenado&gt;</li>')
            && str_contains($request['variables']['body'], '<li><strong>Download</strong> — Resultado: Individual</li>')
            && str_contains($request['variables']['body'], 'Enviado por Ana pelo Yuv Sprint Metrics'));
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'change_simple_column_value') && $request['variables']['column'] === 'color_mm7q3yt1' && $request['variables']['value'] === 'Ag. Validação');
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'change_multiple_column_values') && json_decode($request['variables']['values'], true) === ['numeric_mm8t4ysk' => '20']);
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'change_multiple_column_values')
            && json_decode($request['variables']['values'], true) === ['multiple_person_mm8811a9' => ['personsAndTeams' => [['id' => 42, 'kind' => 'person']]]]);
    }

    public function test_publishing_keeps_estimate_and_responsible_already_filled(): void
    {
        $this->items['55'] = $this->item('55', '456', 'Playback concatenado', estimate: '5', person: 'Cássio Rezende Alvarenga');

        ConsultingTestServer::actingAs($this->user())->tool(PublishScopeTool::class, [
            'item_id' => '55', 'board' => 'backlog', 'hours' => 20, 'responsible' => 'Rafael Souza da Silva', 'scope' => '# Escopo',
        ])->assertOk()->assertSee(['mantida (5h, já preenchida)', 'mantido (Cássio Rezende Alvarenga, já preenchido)']);

        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request['query'], 'change_multiple_column_values'));
    }

    public function test_publishing_again_replaces_the_existing_scope_comment(): void
    {
        $this->items['55'] = $this->item('55', '456', 'Playback concatenado', updates: [$this->update('9001', '<h1>[YUV - Vídeo] – Concatenação</h1><p>Escopo Funcional V1</p>')]);

        ConsultingTestServer::actingAs($this->user())->tool(PublishScopeTool::class, ['item_id' => '55', 'board' => 'backlog', 'scope' => '# Escopo corrigido', 'replace_existing' => true])
            ->assertOk()->assertSee(['"acao":"substituido"', '"updateId":"9001"']);

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'edit_update') && $request['variables']['id'] === '9001'
            && str_contains($request['variables']['body'], '<h1>Escopo corrigido</h1>'));
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request['query'], 'create_update'));
    }

    public function test_a_second_scope_requires_an_explicit_replacement_without_writing_anything(): void
    {
        $this->items['55'] = $this->item('55', '456', 'Playback', updates: [$this->update('9001', '<p>Escopo Funcional V1</p>')]);

        ConsultingTestServer::actingAs($this->user())->tool(PublishScopeTool::class, ['item_id' => '55', 'board' => 'backlog', 'scope' => '# Outro escopo'])
            ->assertHasErrors(['O card já tem um Escopo Funcional V1.']);

        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request['query'], 'mutation'));
    }

    public function test_an_existing_scope_beyond_the_first_hundred_comments_is_not_duplicated(): void
    {
        $updates = array_map(fn (int $number): array => $this->update((string) $number, '<p>Comentário comum</p>'), range(1, 100));
        $this->items['55'] = $this->item('55', '456', 'Playback', updates: $updates);
        $this->extraUpdates = [$this->update('9001', '<p>Escopo Funcional V1</p>')];

        ConsultingTestServer::actingAs($this->user())->tool(PublishScopeTool::class, ['item_id' => '55', 'board' => 'backlog', 'scope' => '# Outro escopo'])
            ->assertHasErrors(['O card já tem um Escopo Funcional V1.']);

        Http::assertSent(fn (Request $request): bool => ($request['variables']['page'] ?? null) === 2);
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request['query'], 'mutation'));
    }

    public function test_simultaneous_publication_of_a_card_is_refused_before_calling_monday(): void
    {
        $lock = Cache::lock('mcp:publish-scope:456:55', 120);
        $lock->get();
        try {
            ConsultingTestServer::actingAs($this->user())->tool(PublishScopeTool::class, ['item_id' => '55', 'board' => 'backlog', 'scope' => '# Escopo'])
                ->assertHasErrors(['Este card já está sendo publicado.']);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_a_board_without_the_validation_status_does_not_receive_a_comment(): void
    {
        $this->items['55'] = $this->item('55', '456', 'Playback');
        $this->items['55']['board']['columns'][0]['settings_str'] = '{"labels":{"0":"Backlog"}}';

        ConsultingTestServer::actingAs($this->user())->tool(PublishScopeTool::class, ['item_id' => '55', 'board' => 'backlog', 'scope' => '# Escopo'])
            ->assertHasErrors(['Nenhuma alteração foi enviada.']);

        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request['query'], 'mutation'));
    }

    public function test_created_scope_is_marked_as_v1_even_when_its_title_omits_the_version(): void
    {
        $this->items['55'] = $this->item('55', '456', 'Playback');

        ConsultingTestServer::actingAs($this->user())->tool(PublishScopeTool::class, ['item_id' => '55', 'board' => 'backlog', 'scope' => '# Playback'])->assertOk();

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'create_update') && str_contains($request['variables']['body'], '<strong>Escopo Funcional V1</strong>'));
    }

    public function test_a_card_from_another_board_is_refused(): void
    {
        $this->items['55'] = $this->item('55', '456', 'Playback concatenado');

        ConsultingTestServer::actingAs($this->user())->tool(PublishScopeTool::class, ['item_id' => '55', 'board' => 'funil', 'scope' => '# Escopo'])
            ->assertHasErrors(['O card 55 não foi encontrado no quadro Funil de Demandas.']);
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request['query'], 'create_update') || str_contains((string) $request['query'], 'change_'));
    }

    public function test_failure_after_posting_the_comment_is_reported_as_incomplete(): void
    {
        $this->items['55'] = $this->item('55', '456', 'Playback');
        $this->failStatusChange = true;

        ConsultingTestServer::actingAs($this->user())->tool(PublishScopeTool::class, ['item_id' => '55', 'board' => 'backlog', 'scope' => '# Escopo'])
            ->assertOk()->assertSee(['"completo":false', '"acao":"criado"', 'há etapas pendentes']);
    }

    private function user(): User
    {
        return User::factory()->canUpdateItems()->create(['name' => 'Ana']);
    }

    /**
     * Respostas do Monday falso, pela consulta enviada.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function monday(string $query, array $variables): array
    {
        $item = fn (): array => $this->items[(string) ($variables['ids'][0] ?? '')] ?? [];

        return match (true) {
            str_contains($query, 'updates(limit: 100, page: $page)') => ['items' => [['updates' => $this->extraUpdates]]],
            str_contains($query, 'updates(limit: 100)') => ['items' => $item() === [] ? [] : [$item()]],
            str_contains($query, 'linked_item_ids } } } }') => ['items' => [['column_values' => [['linked_item_ids' => $this->linked]]]]],
            str_contains($query, 'items_page') => ['boards' => [['items_page' => ['items' => $this->funnelSearch]]]],
            str_contains($query, 'create_update') => ['create_update' => ['id' => '777']],
            str_contains($query, 'edit_update') => ['edit_update' => ['id' => $variables['id']]],
            str_contains($query, 'users(kind') => ['users' => [['id' => '42', 'name' => 'Rafael Souza da Silva', 'enabled' => true], ['id' => '43', 'name' => 'Ana', 'enabled' => true]]],
            str_contains($query, 'change_') => ['change' => ['id' => $variables['item'] ?? '']],
            str_contains($query, 'assets(ids') => ['assets' => []],
            default => ['items' => $item() === [] ? [] : [['id' => $item()['id'], 'board' => $item()['board']]]],
        };
    }

    /**
     * @param  list<array<string, mixed>>  $updates
     * @return array<string, mixed>
     */
    private function item(string $id, string $board, string $name, array $updates = [], string $status = 'Adicionado ao Backlog', string $estimate = '', string $person = ''): array
    {
        $funnel = $board === '789';
        $statusColumn = $funnel ? 'bug_status' : 'color_mm7q3yt1';
        $estimateColumn = $funnel ? 'numeric_mm7yfxbr' : 'numeric_mm8t4ysk';

        return [
            'id' => $id, 'name' => $name, 'url' => "https://yuv.monday.com/boards/{$board}/pulses/{$id}",
            'created_at' => '2026-11-18T10:00:00Z', 'updated_at' => '2026-11-18T10:00:00Z', 'creator' => ['name' => 'PM'],
            'group' => ['title' => 'Backlog', 'color' => '#579bfc'],
            'board' => ['id' => $board, 'name' => $funnel ? 'Funil de Demandas' : 'Backlog', 'columns' => [
                ['id' => $statusColumn, 'title' => 'Status', 'type' => 'status', 'settings_str' => $funnel ? self::FUNNEL_STATUS : self::BACKLOG_STATUS],
                ['id' => $estimateColumn, 'title' => 'Estimativa', 'type' => 'numbers', 'settings_str' => '{}'],
                ['id' => 'multiple_person_mm8811a9', 'title' => 'Pessoas', 'type' => 'people', 'settings_str' => '{}'],
            ]],
            'assets' => [],
            'column_values' => [
                ['id' => $statusColumn, 'text' => $status, 'type' => 'status', 'value' => null, 'column' => ['title' => 'Status'], 'label_style' => ['color' => '#579bfc']],
                ['id' => $estimateColumn, 'text' => $estimate, 'type' => 'numbers', 'value' => null, 'column' => ['title' => 'Estimativa']],
                ['id' => 'multiple_person_mm8811a9', 'text' => $person, 'type' => 'people', 'value' => null, 'column' => ['title' => 'Pessoas']],
            ],
            'subitems' => [],
            'updates' => $updates,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function update(string $id, string $body): array
    {
        return ['id' => $id, 'body' => $body, 'created_at' => '2026-11-18T10:00:00Z', 'creator' => ['name' => 'PM'], 'assets' => [], 'replies' => []];
    }
}

/**
 * Servidor só do teste: as ferramentas do escopo de consultoria ainda são registradas no MetricsServer em outra etapa.
 */
class ConsultingTestServer extends Server
{
    protected array $tools = [MondayCardTool::class, TaskHistoryTool::class, PublishScopeTool::class];

    protected array $prompts = [ConsultingScopePrompt::class];
}
