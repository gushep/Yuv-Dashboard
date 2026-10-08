<?php

namespace Tests\Feature;

use App\Models\MondayConnection;
use App\Models\ReleaseSummary;
use App\Models\User;
use App\Services\ReleaseSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReleaseSummariesTest extends TestCase
{
    use RefreshDatabase;

    private const SPEC = '[YUV - App] – Pins clicáveis no histórico Tipo: Melhoria | Prioridade: Média OBJETIVO Exibir no app um pin para cada posição do histórico do ativo, com detalhes ao clicar. USER STORY Como cliente, quero ver cada posição. CONTEXTO Hoje o app mostra só início e fim.';

    private const EMPTY_TEMPLATE = '🎯 CONTEXTO Por que essa tarefa existe? Qual problema/dor resolve? ... 📦 ESCOPO O que inclui: * ...';

    public function test_release_uses_the_spec_objective_then_the_stored_summary_and_flags_outdated_summaries(): void
    {
        $this->prepareRelease();
        $comments = $this->comments();
        ReleaseSummary::factory()->create(['item_id' => '2', 'summary' => 'Corrigido o envio de eventos via webhook da API.', 'source_hash' => app(ReleaseSummaryService::class)->sourceHash('Envio de eventos', $comments['2'])]);
        ReleaseSummary::factory()->create(['item_id' => '3', 'summary' => 'Configurada a nova VPN do time de infraestrutura.', 'source_hash' => str_repeat('0', 64)]);

        $response = $this->postJson('/api/release', ['sprint' => 17])->assertOk()
            ->assertJsonPath('withoutDescription', 1)->assertJsonPath('staleSummaries', 1);

        $items = collect($response->json('groups'))->flatMap(fn (array $group): array => $group['items'])->keyBy('id');
        $this->assertSame('Exibir no app um pin para cada posição do histórico do ativo, com detalhes ao clicar.', $items['1']['description']);
        $this->assertSame('ficha', $items['1']['descriptionSource']);
        $this->assertSame(['resumo', false], [$items['2']['descriptionSource'], $items['2']['descriptionStale']]);
        $this->assertSame(['resumo', true], [$items['3']['descriptionSource'], $items['3']['descriptionStale']]);
        $this->assertNull($items['4']['description']);
    }

    public function test_export_lists_only_cards_without_spec_or_up_to_date_summary(): void
    {
        $this->prepareRelease();
        Storage::fake('local');
        ReleaseSummary::factory()->create(['item_id' => '2', 'summary' => 'Corrigido o envio de eventos via webhook da API.', 'source_hash' => app(ReleaseSummaryService::class)->sourceHash('Envio de eventos', $this->comments()['2'])]);

        $this->artisan('release:summaries-export 17')->expectsOutputToContain('2 card(s) para resumir')->assertSuccessful();

        $export = json_decode(Storage::disk('local')->get('release-summaries/sprint-17.json'), true);
        $this->assertSame(['3', '4'], array_column($export['items'], 'id'));
        $this->assertStringNotContainsString('Por que essa tarefa existe', $export['items'][1]['text']);
        $this->assertSame(app(ReleaseSummaryService::class)->sourceHash('Configuração da VPN', $this->comments()['3']), $export['items'][0]['hash']);
    }

    public function test_import_stores_valid_summaries_and_rejects_links_or_short_texts(): void
    {
        Storage::fake('local');
        $hash = str_repeat('a', 64);
        Storage::disk('local')->put('ok.json', json_encode(['items' => [['id' => '3', 'hash' => $hash, 'summary' => 'Configurada a nova VPN do time de infraestrutura.']]]));
        Storage::disk('local')->put('invalid.json', json_encode(['items' => [
            ['id' => '4', 'hash' => $hash, 'summary' => 'Veja os detalhes em https://exemplo.com.br'],
            ['id' => '5', 'hash' => $hash, 'summary' => 'Curto.'],
        ]]));

        $this->artisan('release:summaries-import ok.json')->expectsOutputToContain('1 resumo(s) gravado(s)')->assertSuccessful();
        $this->artisan('release:summaries-import invalid.json')->assertFailed();

        $this->assertSame(['3'], ReleaseSummary::query()->pluck('item_id')->all());
        $this->assertSame('claude-code', ReleaseSummary::query()->value('generated_by'));
    }

    private function prepareRelease(): void
    {
        $this->actingAs(User::factory()->create());
        $connection = MondayConnection::current();
        $connection->board_id = '123';
        $connection->token = 'token-secreto';
        $connection->column_map = ['Sprint' => 'sprint'];
        $connection->save();
        $row = fn (string $id, string $name): array => ['Id' => $id, 'Nome' => $name, 'Tipo' => 'Melhoria', 'Status' => 'Implementado', 'Sprint' => 17, 'Responsavel' => 'Ana', 'Url' => ''];
        Cache::put('monday-sync-1', [
            'rows' => [$row('1', 'Pins no histórico'), $row('2', 'Envio de eventos'), $row('3', 'Configuração da VPN'), $row('4', 'Cadastro de equipamentos')],
            'sprints' => [], 'syncedAt' => now()->toIso8601String(),
        ], 3600);
        $comments = $this->comments();
        Http::fake(['api.monday.com/v2' => Http::response(['data' => ['items' => array_map(fn (string $id): array => ['id' => $id, 'updates' => $comments[$id]], array_keys($comments))]])]);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function comments(): array
    {
        return [
            '1' => [['id' => 'u1', 'text_body' => self::EMPTY_TEMPLATE, 'replies' => []], ['id' => 'u2', 'text_body' => self::SPEC, 'replies' => []]],
            '2' => [['id' => 'u3', 'text_body' => 'Validado com o cliente, a solicitação pode ser encerrada.', 'replies' => []]],
            '3' => [['id' => 'u4', 'text_body' => 'VPN nova configurada para o time.', 'replies' => []]],
            '4' => [['id' => 'u5', 'text_body' => self::EMPTY_TEMPLATE, 'replies' => []]],
        ];
    }
}
