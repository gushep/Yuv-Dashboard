<?php

namespace App\Services;

use App\Http\Controllers\MondayController;
use App\Models\MondayConnection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Abertura de demandas de melhoria/feature no Funil de Demandas (quadro do Roadmap) pelo botão Novo Ticket,
 * com os mesmos campos do formulário de demandas do Monday. As colunas são reconhecidas pelo título.
 */
class FeatureRequestService
{
    public const STATUS = 'Aguardando revisão';

    /** Bug é Sustentação: fica fora dos tipos aceitos aqui. */
    public const EXCLUDED_TYPES = ['Bug'];

    /**
     * Campo do formulário => títulos aceitos da coluna (normalizados) e tipos de coluna aceitos.
     *
     * @var array<string, array{titles: list<string>, types: list<string>}>
     */
    private const COLUMNS = [
        'requester' => ['titles' => ['nome solicitante', 'solicitante'], 'types' => ['text']],
        'origin' => ['titles' => ['origem'], 'types' => ['status']],
        'type' => ['titles' => ['tipo'], 'types' => ['status']],
        'module' => ['titles' => ['modulo'], 'types' => ['status']],
        'description' => ['titles' => ['descricao da demanda', 'descricao'], 'types' => ['long_text', 'text']],
        'affected' => ['titles' => ['quem e afetado?', 'quem e afetado'], 'types' => ['dropdown']],
        'solution' => ['titles' => ['solucao proposta'], 'types' => ['long_text', 'text']],
        'evidence' => ['titles' => ['evidencias'], 'types' => ['file']],
        'requirements' => ['titles' => ['requisitos'], 'types' => ['long_text', 'text']],
        'motivation' => ['titles' => ['motivacao da demanda para o negocio', 'motivacao'], 'types' => ['status']],
        'mrr' => ['titles' => ['possivel mrr'], 'types' => ['status']],
        'severity' => ['titles' => ['severidade', 'impacto'], 'types' => ['status']],
        'status' => ['titles' => ['status'], 'types' => ['status']],
        'date' => ['titles' => ['data do registro'], 'types' => ['date']],
    ];

    /** Campos de escolha e o nome usado nas mensagens. */
    private const CHOICES = ['origin' => 'Origem', 'type' => 'Tipo', 'module' => 'Módulo', 'motivation' => 'Motivação', 'mrr' => 'Possível MRR', 'severity' => 'Severidade'];

    public function __construct(private MondayService $monday) {}

    /**
     * Opções de cada campo de escolha, na ordem do Monday.
     *
     * @return array{boardName: string, origin: list<string>, type: list<string>, module: list<string>, affected: list<string>, motivation: list<string>, mrr: list<string>, severity: list<string>}
     */
    public function options(MondayConnection $connection): array
    {
        ['name' => $name, 'columns' => $columns] = $this->board($connection);
        $fields = [...array_keys(self::CHOICES), 'affected'];

        return ['boardName' => $name, ...array_combine($fields, array_map(fn (string $field): array => $this->labels($columns[$field], $field), $fields))];
    }

    /**
     * Cria a demanda com status Aguardando revisão e a data de hoje como Data do registro.
     *
     * @param  array{title: string, requester: string, origin: string, type: string, module: string, description: string, affected: list<string>, solution: string, requirements: string, motivation: string, mrr: string, severity: string}  $request
     * @return array{id: string, url: string, boardName: string}
     */
    public function create(MondayConnection $connection, array $request): array
    {
        ['name' => $name, 'columns' => $columns] = $this->board($connection);
        foreach (self::CHOICES as $field => $label) {
            if (! in_array($request[$field], $this->labels($columns[$field], $field), true)) {
                throw new RuntimeException("{$label} inválido(a) para o Funil de Demandas.");
            }
        }
        if (array_diff($request['affected'], $this->labels($columns['affected'], 'affected')) !== []) {
            throw new RuntimeException('Quem é afetado tem uma opção que não existe no Funil de Demandas.');
        }
        if (! in_array(self::STATUS, $this->labels($columns['status'], 'status'), true)) {
            throw new RuntimeException('A coluna Status do Funil de Demandas não tem o rótulo "'.self::STATUS.'".');
        }

        $text = fn (array $column, string $value): mixed => $column['type'] === 'long_text' ? ['text' => $value] : $value;
        $values = [
            $columns['requester']['id'] => $request['requester'],
            $columns['description']['id'] => $text($columns['description'], $request['description']),
            $columns['solution']['id'] => $text($columns['solution'], $request['solution']),
            $columns['requirements']['id'] => $text($columns['requirements'], $request['requirements']),
            $columns['affected']['id'] => ['labels' => array_values($request['affected'])],
            $columns['status']['id'] => ['label' => self::STATUS],
        ];
        foreach (array_keys(self::CHOICES) as $field) {
            $values[$columns[$field]['id']] = ['label' => $request[$field]];
        }
        if ($columns['date'] !== null) {
            $values[$columns['date']['id']] = ['date' => now()->toDateString()];
        }

        $mutation = 'mutation ($board: ID!, $name: String!, $values: JSON!) { create_item(board_id: $board, item_name: $name, column_values: $values) { id url } }';
        $created = $this->monday->query((string) $connection->token, $mutation, [
            'board' => (string) $connection->roadmap_board_id,
            'name' => $request['title'],
            'values' => json_encode($values, JSON_UNESCAPED_UNICODE),
        ])['create_item'] ?? null;
        if (empty($created['id'])) {
            throw new RuntimeException('O Monday não confirmou a criação da demanda.');
        }
        Cache::forget(MondayController::BOARD_TAB_CACHE.'roadmap');

        return ['id' => (string) $created['id'], 'url' => (string) ($created['url'] ?? ''), 'boardName' => $name];
    }

    /**
     * Evidência anexada na coluna Evidências da demanda.
     */
    public function attachEvidence(MondayConnection $connection, string $itemId, UploadedFile $file): void
    {
        $columns = $this->board($connection)['columns'];
        $this->monday->attachFileToColumn((string) $connection->token, (string) $connection->roadmap_board_id, $itemId, $columns['evidence']['id'], $file);
    }

    /**
     * Nome e colunas do Funil de Demandas usadas pelo formulário; todas são obrigatórias, menos a Data do registro.
     *
     * @return array{name: string, columns: array<string, ?array{id: string, type: string, settings_str: string}>}
     */
    private function board(MondayConnection $connection): array
    {
        if (blank($connection->roadmap_board_id)) {
            throw new RuntimeException('Configure o quadro do Roadmap (Funil de Demandas) nas Configurações antes de abrir demandas.');
        }
        $data = $this->monday->query((string) $connection->token, 'query ($board: [ID!]!) { boards(ids: $board) { name columns { id title type settings_str } } }',
            ['board' => [(string) $connection->roadmap_board_id]]);
        $board = $data['boards'][0] ?? throw new RuntimeException('Funil de Demandas não encontrado ou sem permissão de leitura.');

        $columns = [];
        foreach (self::COLUMNS as $field => $rule) {
            $column = collect($board['columns'] ?? [])->first(fn (array $column): bool => in_array($column['type'], $rule['types'], true)
                && in_array(mb_strtolower(trim(preg_replace('/\s+/', ' ', Str::ascii((string) $column['title'])))), $rule['titles'], true));
            if ($column === null && $field !== 'date') {
                throw new RuntimeException('O Funil de Demandas não tem a coluna "'.Str::headline($rule['titles'][0]).'".');
            }
            $columns[$field] = $column ? ['id' => (string) $column['id'], 'type' => (string) $column['type'], 'settings_str' => (string) ($column['settings_str'] ?? '')] : null;
        }

        return ['name' => (string) $board['name'], 'columns' => $columns];
    }

    /**
     * @param  array{settings_str: string}  $column
     * @return list<string>
     */
    private function labels(array $column, string $field): array
    {
        $labels = array_values(array_unique(array_column($this->monday->statusOptions($column), 'label')));

        return $field === 'type' ? array_values(array_diff($labels, self::EXCLUDED_TYPES)) : $labels;
    }
}
