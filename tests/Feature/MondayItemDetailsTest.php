<?php

namespace Tests\Feature;

use App\Models\MondayConnection;
use App\Models\User;
use App\Screen;
use App\Services\MondaySnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MondayItemDetailsTest extends TestCase
{
    use RefreshDatabase;

    private const STATUS_SETTINGS = '{"labels":{"0":"Em progresso","1":"Feito","2":"Em QA","5":""},"labels_colors":{"0":{"color":"#fdab3d"},"1":{"color":"#00c875"},"2":{"color":"#a25ddc"}},"labels_positions_v2":{"0":1,"1":2,"2":0}}';

    protected function setUp(): void
    {
        parent::setUp();
        $connection = MondayConnection::current();
        $connection->board_id = '123';
        $connection->token = 'token-secreto';
        $connection->column_map = ['Sprint' => 'sprint', 'Status' => 'status'];
        $connection->save();
        Http::preventStrayRequests();
    }

    public function test_item_details_require_access_to_the_raw_data_screen(): void
    {
        $this->getJson('/api/items/55')->assertUnauthorized();
        $this->actingAs(User::factory()->withScreens(Screen::ControlTower)->create());
        $this->getJson('/api/items/55')->assertForbidden();
        $this->getJson('/api/items/55/activity')->assertForbidden();
        $this->actingAs(User::factory()->withScreens(Screen::RawData)->create())->getJson('/api/items/abc')->assertNotFound();
    }

    public function test_item_details_return_all_columns_status_options_subitems_and_comments_as_plain_text(): void
    {
        Http::fake(['api.monday.com/v2' => Http::response(['data' => ['items' => [$this->item()]]])]);

        $this->actingAs(User::factory()->withScreens(Screen::RawData)->create())
            ->getJson('/api/items/55')->assertOk()
            ->assertJsonPath('name', 'Corrigir login')
            ->assertJsonPath('board', 'Tarefas')
            ->assertJsonPath('group', ['title' => 'Sprint 17', 'color' => '#579bfc'])
            ->assertJsonPath('statusColumnId', 'status')
            ->assertJsonPath('statusOptions', [
                ['label' => 'Em QA', 'color' => '#a25ddc'],
                ['label' => 'Em progresso', 'color' => '#fdab3d'],
                ['label' => 'Feito', 'color' => '#00c875'],
            ])
            ->assertJsonPath('columns', [
                ['id' => 'status', 'title' => 'Status', 'type' => 'status', 'text' => 'Em progresso', 'color' => '#fdab3d'],
                ['id' => 'epico', 'title' => 'Épico', 'type' => 'board_relation', 'text' => 'Autenticação', 'color' => null],
                ['id' => 'notas', 'title' => 'Notas', 'type' => 'text', 'text' => '', 'color' => null],
            ])
            ->assertJsonPath('subitems', [['id' => '60', 'name' => 'Ajustar tela', 'status' => 'Feito', 'color' => '#00c875', 'people' => 'Ana Souza']])
            ->assertJsonPath('updates.0.author', 'Ana Souza')
            ->assertJsonPath('updates.0.text', "Primeira linha\nSegunda & <final>")
            ->assertJsonPath('updates.0.assets', [['name' => 'print.png', 'url' => 'https://monday.com/files/1']])
            ->assertJsonPath('updates.0.replies.0.text', 'Ok, verificado')
            ->assertJsonPath('updates.0.replies.0.author', 'Automação')
            ->assertJsonPath('canUpdateItems', false)
            ->assertDontSee('token-secreto');
        Http::assertSent(fn (Request $request): bool => $request['variables'] === ['ids' => ['55'], 'columns' => ['status']]);
    }

    public function test_item_details_tell_whether_the_user_may_change_the_status(): void
    {
        Http::fake(['api.monday.com/v2' => Http::response(['data' => ['items' => [$this->item()]]])]);

        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canUpdateItems()->create())
            ->getJson('/api/items/55')->assertOk()->assertJsonPath('canUpdateItems', true);
    }

    public function test_item_from_another_board_is_not_returned(): void
    {
        Http::fake(['api.monday.com/v2' => Http::response(['data' => ['items' => [[...$this->item(), 'board' => ['id' => '999', 'name' => 'Outro']]]]])]);

        $this->actingAs(User::factory()->withScreens(Screen::RawData)->create())
            ->getJson('/api/items/55')->assertUnprocessable()
            ->assertJsonPath('error', 'Card não encontrado no quadro configurado.');
    }

    public function test_item_activity_lists_changes_with_readable_values_and_author_names_without_comments(): void
    {
        Http::fakeSequence('api.monday.com/v2')
            ->push(['data' => ['boards' => [['activity_logs' => [
                ['id' => 'a3', 'event' => 'create_update', 'user_id' => '7', 'created_at' => '17596000000000000', 'data' => '{}'],
                ['id' => 'a2', 'event' => 'update_column_value', 'user_id' => '7', 'created_at' => '17595000000000000', 'data' => json_encode([
                    'column_title' => 'Responsável', 'value' => ['personsAndTeams' => [['id' => 8, 'kind' => 'person']]], 'previous_value' => null,
                ])],
                ['id' => 'a1', 'event' => 'update_column_value', 'user_id' => '7', 'created_at' => '17594000000000000', 'data' => json_encode([
                    'column_title' => 'Status', 'value' => ['label' => ['index' => 1, 'text' => 'Feito']], 'previous_value' => ['label' => ['index' => 0, 'text' => 'Em progresso']],
                ])],
            ]]]]])
            ->push(['data' => ['users' => [['id' => '7', 'name' => 'Ana Souza'], ['id' => '8', 'name' => 'Bruno Lima']]]]);

        $this->actingAs(User::factory()->withScreens(Screen::RawData)->create())
            ->getJson('/api/items/55/activity')->assertOk()
            ->assertJsonCount(2, 'activity')
            ->assertJsonPath('activity.0.author', 'Ana Souza')
            ->assertJsonPath('activity.0.column', 'Responsável')
            ->assertJsonPath('activity.0.to', 'Bruno Lima')
            ->assertJsonPath('activity.1.from', 'Em progresso')
            ->assertJsonPath('activity.1.to', 'Feito')
            ->assertJsonPath('activity.1.createdAt', '2025-10-02T10:13:20+00:00');
    }

    public function test_changing_status_requires_the_specific_permission(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::RawData)->create())
            ->postJson('/api/items/55/status', ['status' => 'Feito'])->assertForbidden();
        $this->actingAs(User::factory()->withScreens(Screen::Dashboard)->canUpdateItems()->create())
            ->postJson('/api/items/55/status', ['status' => 'Feito'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_permitted_user_changes_the_status_in_monday_and_the_cached_snapshot_is_discarded(): void
    {
        Cache::put(MondaySnapshotService::CACHE_KEY, ['rows' => []], 3600);
        Http::fakeSequence('api.monday.com/v2')
            ->push(['data' => ['items' => [['id' => '55', 'board' => ['id' => '123', 'columns' => [['id' => 'status', 'settings_str' => self::STATUS_SETTINGS]]]]]]])
            ->push(['data' => ['change_simple_column_value' => ['id' => '55']]]);

        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canUpdateItems()->create())
            ->postJson('/api/items/55/status', ['status' => 'Em QA'])
            ->assertOk()->assertJsonPath('status', 'Em QA');

        Http::assertSent(fn (Request $request): bool => str_starts_with($request['query'], 'mutation')
            && $request['variables'] === ['board' => '123', 'item' => '55', 'column' => 'status', 'value' => 'Em QA']);
        $this->assertNull(Cache::get(MondaySnapshotService::CACHE_KEY));
    }

    public function test_status_change_rejects_missing_or_unknown_labels(): void
    {
        Http::fake(['api.monday.com/v2' => Http::response(['data' => ['items' => [['id' => '55', 'board' => ['id' => '123', 'columns' => [['id' => 'status', 'settings_str' => self::STATUS_SETTINGS]]]]]]])]);
        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canUpdateItems()->create());

        $this->postJson('/api/items/55/status', [])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson('/api/items/55/status', ['status' => 'Aprovado'])
            ->assertUnprocessable()->assertJsonPath('error', 'Status inválido para este quadro.');
        Http::assertSentCount(1);
    }

    public function test_commenting_and_attaching_require_the_permission_to_update_cards(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::RawData)->create());

        $this->postJson('/api/items/55/comments', ['text' => 'Testado'])->assertForbidden();
        $this->post('/api/items/55/comments/777/files', ['file' => UploadedFile::fake()->image('evidencia.png')], ['Accept' => 'application/json'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_comment_needs_text_unless_attachments_follow(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canUpdateItems()->create());

        $this->postJson('/api/items/55/comments', ['text' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['text' => 'Escreva um comentário ou anexe pelo menos um arquivo.']);
        Http::assertNothingSent();

        Http::fakeSequence('api.monday.com/v2')
            ->push(['data' => ['items' => [['id' => '55', 'board' => ['id' => '123']]]]])
            ->push(['data' => ['create_update' => ['id' => '778']]]);
        $this->postJson('/api/items/55/comments', ['text' => '', 'hasAttachments' => true])->assertOk()->assertJsonPath('updateId', '778');
        Http::assertSent(fn (Request $request): bool => str_starts_with((string) ($request['variables']['body'] ?? ''), '<p>Evidências anexadas.</p>'));
    }

    public function test_comment_is_published_with_escaped_text_and_its_author(): void
    {
        Http::fakeSequence('api.monday.com/v2')
            ->push(['data' => ['items' => [['id' => '55', 'board' => ['id' => '123']]]]])
            ->push(['data' => ['create_update' => ['id' => '777']]]);
        $user = User::factory()->withScreens(Screen::RawData)->canUpdateItems()->create(['name' => 'Carla QA']);

        $this->actingAs($user)->postJson('/api/items/55/comments', ['text' => "Cenário 1 ok\n<b>Cenário 2</b> falhou"])
            ->assertOk()->assertJsonPath('updateId', '777');

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['query'], 'create_update')
            && $request['variables']['body'] === '<p>Cenário 1 ok<br>'."\n".'&lt;b&gt;Cenário 2&lt;/b&gt; falhou</p><p><em>Enviado por Carla QA pelo Yuv Sprint Metrics</em></p>');
    }

    public function test_file_is_attached_to_the_comment_of_the_card(): void
    {
        Http::fakeSequence('api.monday.com/v2/file')->push(['data' => ['add_file_to_update' => ['id' => '9001']]]);
        Http::fakeSequence('api.monday.com/v2')
            ->push(['data' => ['items' => [['id' => '55', 'board' => ['id' => '123']]], 'updates' => [['id' => '777', 'item_id' => '55']]]]);

        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canUpdateItems()->create())
            ->post('/api/items/55/comments/777/files', ['file' => UploadedFile::fake()->image('evidencia.png')], ['Accept' => 'application/json'])
            ->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.monday.com/v2/file'
            && $request->hasFile('variables[file]', null, 'evidencia.png')
            && str_contains((string) collect($request->data())->firstWhere('name', 'query')['contents'], 'add_file_to_update(update_id: 777'));
    }

    public function test_file_is_not_attached_to_a_comment_of_another_card(): void
    {
        Http::fake(['api.monday.com/v2' => Http::response(['data' => ['items' => [['id' => '55', 'board' => ['id' => '123']]], 'updates' => [['id' => '777', 'item_id' => '99']]]])]);

        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canUpdateItems()->create())
            ->post('/api/items/55/comments/777/files', ['file' => UploadedFile::fake()->image('evidencia.png')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('error', 'Comentário não encontrado neste card.');
        Http::assertSentCount(1);
    }

    public function test_file_refused_by_monday_returns_its_reason(): void
    {
        Http::fakeSequence('api.monday.com/v2/file')->push(['errors' => [['message' => 'File too large']]]);
        Http::fakeSequence('api.monday.com/v2')
            ->push(['data' => ['items' => [['id' => '55', 'board' => ['id' => '123']]], 'updates' => [['id' => '777', 'item_id' => '55']]]]);

        $this->actingAs(User::factory()->withScreens(Screen::RawData)->canUpdateItems()->create())
            ->post('/api/items/55/comments/777/files', ['file' => UploadedFile::fake()->create('video.mp4', 100)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('error', 'Monday recusou o arquivo: File too large.');
    }

    /**
     * @return array<string, mixed>
     */
    private function item(): array
    {
        return [
            'id' => '55', 'name' => 'Corrigir login', 'url' => 'https://monday.com/boards/123/pulses/55',
            'created_at' => '2026-10-01T12:00:00Z', 'updated_at' => '2026-10-05T12:00:00Z',
            'creator' => ['name' => 'Bruno Lima'], 'group' => ['title' => 'Sprint 17', 'color' => '#579bfc'],
            'board' => ['id' => '123', 'name' => 'Tarefas', 'columns' => [['id' => 'status', 'settings_str' => self::STATUS_SETTINGS]]],
            'column_values' => [
                ['id' => 'name', 'text' => 'Corrigir login', 'type' => 'name', 'column' => ['title' => 'Nome']],
                ['id' => 'status', 'text' => 'Em progresso', 'type' => 'status', 'column' => ['title' => 'Status'], 'label_style' => ['color' => '#fdab3d']],
                ['id' => 'epico', 'text' => null, 'type' => 'board_relation', 'display_value' => 'Autenticação', 'column' => ['title' => 'Épico']],
                ['id' => 'notas', 'text' => '', 'type' => 'text', 'column' => ['title' => 'Notas']],
                ['id' => 'subitens', 'text' => 'Ajustar tela', 'type' => 'subtasks', 'column' => ['title' => 'Subitens']],
            ],
            'subitems' => [['id' => '60', 'name' => 'Ajustar tela', 'column_values' => [
                ['text' => 'Feito', 'type' => 'status', 'label_style' => ['color' => '#00c875']],
                ['text' => 'Ana Souza', 'type' => 'people'],
            ]]],
            'updates' => [[
                'id' => '900', 'body' => '<p>Primeira linha</p><p>Segunda &amp; &lt;final&gt;</p>', 'created_at' => '2026-10-02T10:00:00Z',
                'creator' => ['name' => 'Ana Souza'], 'assets' => [['name' => 'print.png', 'url' => 'https://monday.com/files/1']],
                'replies' => [['id' => '901', 'body' => 'Ok,&nbsp;verificado', 'created_at' => '2026-10-02T11:00:00Z', 'creator' => null]],
            ]],
        ];
    }
}
