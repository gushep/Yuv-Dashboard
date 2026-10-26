<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MondayService
{
    public function query(string $token, string $query, array $variables = []): array
    {
        $response = Http::timeout(45)
            ->withHeaders(['Authorization' => $token, 'API-Version' => '2026-07'])
            ->post('https://api.monday.com/v2', ['query' => $query, 'variables' => $variables]);

        if (! $response->successful()) {
            throw new RuntimeException('Monday respondeu HTTP '.$response->status().'. Confira o token e as permissões.');
        }
        $body = $response->json();
        if (! empty($body['errors'])) {
            throw new RuntimeException('Monday: '.implode('; ', array_column($body['errors'], 'message')));
        }
        if (empty($body['data'])) {
            throw new RuntimeException('O Monday não retornou dados.');
        }

        return $body['data'];
    }

    public function board(string $token, string $boardId): array
    {
        $data = $this->query($token, 'query ($board: [ID!]!) { boards(ids: $board) { id name columns { id title type } } }', ['board' => [$boardId]]);

        return $data['boards'][0] ?? throw new RuntimeException('Quadro não encontrado ou sem permissão de leitura.');
    }

    /**
     * Colunas do quadro com as configurações (rótulos de status, quadros vinculados).
     *
     * @return list<array{id: string, title: string, type: string, settings_str: string}>
     */
    public function boardColumns(string $token, string $boardId): array
    {
        $data = $this->query($token, 'query ($board: [ID!]!) { boards(ids: $board) { columns { id title type settings_str } } }', ['board' => [$boardId]]);

        return $data['boards'][0]['columns'] ?? throw new RuntimeException('Quadro não encontrado ou sem permissão de leitura.');
    }

    public function boards(string $token): array
    {
        $boards = [];
        for ($page = 1; $page <= 20; $page++) {
            $data = $this->query($token, 'query ($page: Int!) { boards(limit: 100, page: $page) { id name } }', ['page' => $page]);
            $batch = $data['boards'] ?? [];
            foreach ($batch as $board) {
                $boards[] = ['id' => (string) $board['id'], 'name' => (string) $board['name']];
            }
            if (count($batch) < 100) {
                break;
            }
        }

        return $boards;
    }

    /**
     * Uma página do log de atividades do quadro, do evento mais recente para o mais antigo.
     *
     * @return list<array{id: string, event: string, user_id: ?string, created_at: string, data: string}>
     */
    public function activityLogs(string $token, string $boardId, ?string $from, string $to, int $page, int $limit): array
    {
        $query = 'query ($board: [ID!]!, $from: ISO8601DateTime, $to: ISO8601DateTime, $page: Int!, $limit: Int!) { boards(ids: $board) { activity_logs(from: $from, to: $to, page: $page, limit: $limit) { id event user_id created_at data } } }';
        $data = $this->query($token, $query, ['board' => [$boardId], 'from' => $from, 'to' => $to, 'page' => $page, 'limit' => $limit]);

        return $data['boards'][0]['activity_logs'] ?? throw new RuntimeException('Quadro não encontrado ou sem permissão de leitura.');
    }

    public function sync(string $token, string $boardId, array $map): array
    {
        $first = <<<'GRAPHQL'
query ($board: [ID!]!) {
  boards(ids: $board) {
    id name columns { id title settings_str }
    items_page(limit: 500) {
      cursor
      items { id name url column_values { id text type ... on BoardRelationValue { display_value } ... on TimeTrackingValue { duration } } }
    }
  }
}
GRAPHQL;
        $next = <<<'GRAPHQL'
query ($cursor: String!) {
  next_items_page(limit: 500, cursor: $cursor) {
    cursor
    items { id name url column_values { id text type ... on BoardRelationValue { display_value } ... on TimeTrackingValue { duration } } }
  }
}
GRAPHQL;

        $data = $this->query($token, $first, ['board' => [$boardId]]);
        $board = $data['boards'][0] ?? throw new RuntimeException('Quadro não encontrado ou sem permissão de leitura.');
        $items = [];
        $page = $board['items_page'] ?? null;
        for ($count = 0; $page !== null; $count++) {
            if ($count >= 100) {
                throw new RuntimeException('A consulta ultrapassou 100 páginas.');
            }
            array_push($items, ...($page['items'] ?? []));
            $cursor = $page['cursor'] ?? null;
            $page = $cursor ? ($this->query($token, $next, ['cursor' => $cursor])['next_items_page'] ?? null) : null;
        }
        $fieldByTitle = ['módulo' => 'Modulo', 'modulo' => 'Modulo', 'descrição' => 'Descricao', 'descricao' => 'Descricao', 'cliente' => 'Cliente'];
        foreach ($board['columns'] ?? [] as $column) {
            $field = $fieldByTitle[mb_strtolower(trim($column['title'] ?? ''))] ?? null;
            if ($field !== null) {
                $map[$field] ??= $column['id'];
            }
        }
        $converted = $this->convertItems($items, $map);
        try {
            $sprintBoard = $this->sprintDates($board, $token, (string) ($map['Sprint'] ?? ''));
        } catch (\Throwable) {
            $sprintBoard = ['dates' => [], 'items' => []];
        }

        return [
            'boardName' => $board['name'],
            'fetched' => count($items),
            ...$converted,
            'sprints' => $sprintBoard['dates'],
            'sprintItems' => $sprintBoard['items'],
            'labels' => $this->statusLabels($board['columns'] ?? []),
            // Rótulos da coluna Status na ordem e cor do Monday: colunas do Kanban da tela Tarefas.
            'statusOptions' => $this->statusOptions(collect($board['columns'] ?? [])->firstWhere('id', $map['Status'] ?? null) ?? []),
            'syncedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * Texto de cada índice das colunas de status. Mudanças em lote no log do Monday trazem só o índice.
     *
     * @return array<string, array<string, string>>
     */
    private function statusLabels(array $columns): array
    {
        $labels = [];
        foreach ($columns as $column) {
            $settings = json_decode($column['settings_str'] ?? '', true);
            if (! is_array($settings['labels'] ?? null)) {
                continue;
            }
            foreach ($settings['labels'] as $index => $label) {
                $text = is_array($label) ? ($label['name'] ?? $label['label'] ?? null) : $label;
                $key = is_array($label) ? ($label['id'] ?? $index) : $index;
                if (is_string($text) && $text !== '') {
                    $labels[(string) $column['id']][(string) $key] = $text;
                }
            }
        }

        return $labels;
    }

    public function convertItems(array $items, array $map): array
    {
        $rows = [];
        $unassignedSupport = [];
        $skipped = 0;

        foreach ($items as $item) {
            $sprintText = $this->text($item, $map['Sprint'] ?? null);
            preg_match_all('/\bSprint\s*(\d+)\b/iu', $sprintText, $matches);
            $numbers = array_map('intval', $matches[1]);
            if (! $numbers && preg_match('/^\s*(\d+)\s*$/', $sprintText, $matches)) {
                $numbers = [(int) $matches[1]];
            }
            if (! $numbers) {
                $skipped++;
                $type = $this->text($item, $map['Tipo'] ?? null);
                if (mb_strtolower(trim($type)) === 'suporte') {
                    $unassignedSupport[] = [
                        'Id' => (string) $item['id'], 'Url' => (string) ($item['url'] ?? ''),
                        'Nome' => (string) $item['name'],
                        'Responsavel' => $this->text($item, $map['Responsavel'] ?? null),
                        'Status' => $this->text($item, $map['Status'] ?? null),
                        'Prioridade' => $this->text($item, $map['Prioridade'] ?? null),
                        'Tipo' => $type, 'Sprint' => null,
                        'Cliente' => trim($this->text($item, $map['Cliente'] ?? null)),
                    ];
                }

                continue;
            }
            foreach (array_unique($numbers) as $sprint) {
                $rows[] = [
                    'Id' => (string) $item['id'], 'Url' => (string) ($item['url'] ?? ''),
                    'Nome' => (string) $item['name'],
                    'Responsavel' => $this->text($item, $map['Responsavel'] ?? null),
                    'Status' => $this->text($item, $map['Status'] ?? null),
                    'Prioridade' => $this->text($item, $map['Prioridade'] ?? null),
                    'Tipo' => $this->text($item, $map['Tipo'] ?? null),
                    'Sprint' => $sprint,
                    'Epico' => $this->text($item, $map['Epico'] ?? null),
                    'Planejada' => $this->text($item, $map['Planejada'] ?? null),
                    'Retrabalho' => $this->flag($this->text($item, $map['Retrabalho'] ?? null)),
                    'BugProducao' => $this->flag($this->text($item, $map['BugProducao'] ?? null)),
                    'ValorAgregado' => $this->flag($this->text($item, $map['ValorAgregado'] ?? null)),
                    'EstH' => $this->hours($item, $map['EstH'] ?? null),
                    'RealH' => $this->hours($item, $map['RealH'] ?? null),
                    'RemainingH' => $this->hours($item, $map['RemainingH'] ?? null),
                    'Impedimento' => $this->text($item, $map['Impedimento'] ?? null),
                    'Modulo' => $this->text($item, $map['Modulo'] ?? null),
                    'Descricao' => trim($this->text($item, $map['Descricao'] ?? null)),
                    'Cliente' => trim($this->text($item, $map['Cliente'] ?? null)),
                ];
            }
        }

        return compact('rows', 'unassignedSupport', 'skipped');
    }

    /** @return array<string, array{available: bool, comments: array}> */
    public function releaseComments(string $token, array $itemIds): array
    {
        $result = [];
        $query = 'query ($ids: [ID!]!, $page: Int!) { items(ids: $ids) { id updates(limit: 100, page: $page) { id text_body created_at creator { name } replies { id body created_at creator { name } } } } }';
        foreach (array_chunk($itemIds, 20) as $chunk) {
            $pending = $chunk;
            $comments = array_fill_keys($chunk, []);
            try {
                for ($page = 1; $pending !== []; $page++) {
                    if ($page > 100) {
                        throw new RuntimeException('Histórico de comentários ultrapassou o limite de leitura.');
                    }
                    $data = $this->query($token, $query, ['ids' => $pending, 'page' => $page]);
                    $next = [];
                    $returned = [];
                    foreach ($data['items'] ?? [] as $item) {
                        $id = (string) $item['id'];
                        $returned[] = $id;
                        if (! array_key_exists($id, $comments) || ! isset($item['updates'])) {
                            continue;
                        }
                        foreach ($item['updates'] as $update) {
                            $comments[$id][(string) $update['id']] = $update;
                        }
                        if (count($item['updates']) === 100) {
                            $next[] = $id;
                        } else {
                            $result[$id] = ['available' => true, 'comments' => array_values($comments[$id])];
                        }
                    }
                    foreach (array_diff($pending, $returned) as $missingId) {
                        $result[$missingId] = ['available' => false, 'comments' => []];
                    }
                    $pending = $next;
                }
            } catch (\Throwable) {
                foreach ($pending as $id) {
                    $result[$id] = ['available' => false, 'comments' => []];
                }
            }
        }

        return $result;
    }

    /**
     * Card completo como aparece no Monday: todas as colunas (inclusive vazias), subitens, comentários com respostas
     * e as opções da coluna de status. Só lê cards do quadro configurado.
     *
     * @return array{id: string, name: string, url: string, createdAt: ?string, updatedAt: ?string, creator: ?string,
     *     board: ?string, group: ?array{title: string, color: ?string}, statusColumnId: ?string,
     *     statusOptions: list<array{label: string, color: ?string}>,
     *     columns: list<array{id: string, title: string, type: string, text: string, color: ?string, files?: list<array<string, mixed>>}>,
     *     subitems: list<array{id: string, name: string, status: string, color: ?string, people: string}>,
     *     attachments: list<array{id: string, name: string, url: string, extension: string, size: int, image: bool}>,
     *     updates: list<array{id: string, author: string, createdAt: string, text: string, images: list<array<string, mixed>>, assets: list<array<string, mixed>>, replies: list<array<string, mixed>>}>}
     */
    public function itemDetails(string $token, string $boardId, string $itemId, ?string $statusColumnId): array
    {
        $query = <<<'GRAPHQL'
query ($ids: [ID!]!, $columns: [String!]) {
  items(ids: $ids) {
    id name url created_at updated_at
    creator { name }
    group { title color }
    board { id name columns(ids: $columns) { id settings_str } }
    assets { id name file_extension file_size public_url url }
    column_values {
      id text type column { title }
      ... on StatusValue { label_style { color } }
      ... on BoardRelationValue { display_value }
      ... on MirrorValue { display_value }
    }
    subitems { id name column_values { text type ... on StatusValue { label_style { color } } } }
    updates(limit: 100) {
      id body created_at creator { name }
      assets { id name file_extension file_size public_url url }
      replies { id body created_at creator { name } }
    }
  }
}
GRAPHQL;
        $item = $this->boardItem($token, $boardId, $query, ['ids' => [$itemId], 'columns' => $statusColumnId ? [$statusColumnId] : null]);
        $assets = $this->itemAssets($token, $item);

        $columns = [];
        foreach ($item['column_values'] ?? [] as $value) {
            if (in_array($value['type'] ?? '', ['name', 'subtasks'], true)) {
                continue;
            }
            $text = trim((string) (! empty($value['display_value']) ? $value['display_value'] : ($value['text'] ?? '')));
            $columns[] = [
                'id' => (string) $value['id'],
                'title' => (string) ($value['column']['title'] ?? $value['id']),
                'type' => (string) ($value['type'] ?? ''),
                'text' => $text,
                'color' => $value['label_style']['color'] ?? null,
                ...(($value['type'] ?? '') === 'file' ? ['files' => $this->assetsIn($text, $assets)] : []),
            ];
        }
        $comment = fn (array $entry): array => [
            'id' => (string) $entry['id'],
            'author' => (string) ($entry['creator']['name'] ?? 'Automação'),
            'createdAt' => (string) $entry['created_at'],
            'text' => $this->plainText((string) ($entry['body'] ?? '')),
            // Imagens coladas no corpo do comentário: o Monday as guarda como <img> no HTML.
            'images' => $this->assetsIn(implode(' ', $this->imageSources((string) ($entry['body'] ?? ''))), $assets),
        ];
        $hasStatusColumn = $statusColumnId && collect($columns)->contains('id', $statusColumnId);

        return [
            'id' => (string) $item['id'],
            'name' => (string) $item['name'],
            'url' => (string) ($item['url'] ?? ''),
            'createdAt' => $item['created_at'] ?? null,
            'updatedAt' => $item['updated_at'] ?? null,
            'creator' => $item['creator']['name'] ?? null,
            'board' => $item['board']['name'] ?? null,
            'group' => isset($item['group']['title']) ? ['title' => (string) $item['group']['title'], 'color' => $item['group']['color'] ?? null] : null,
            'statusColumnId' => $hasStatusColumn ? $statusColumnId : null,
            'statusOptions' => $hasStatusColumn ? $this->statusOptions(collect($item['board']['columns'] ?? [])->firstWhere('id', $statusColumnId) ?? []) : [],
            'columns' => $columns,
            'subitems' => array_map(function (array $subitem): array {
                $values = collect($subitem['column_values'] ?? []);
                $status = $values->firstWhere('type', 'status');

                return [
                    'id' => (string) $subitem['id'],
                    'name' => (string) $subitem['name'],
                    'status' => (string) ($status['text'] ?? ''),
                    'color' => $status['label_style']['color'] ?? null,
                    'people' => (string) ($values->firstWhere('type', 'people')['text'] ?? ''),
                ];
            }, $item['subitems'] ?? []),
            'attachments' => array_values($assets),
            'updates' => array_map(fn (array $update): array => [
                ...$comment($update),
                'assets' => array_values(array_filter(array_map(fn (array $asset): ?array => $assets[(string) $asset['id']] ?? null, $update['assets'] ?? []))),
                'replies' => array_map($comment, $update['replies'] ?? []),
            ], $item['updates'] ?? []),
        ];
    }

    /**
     * Todos os arquivos do card (colunas de arquivo, anexos e imagens coladas nos comentários), por id.
     * O link é o público temporário do Monday (vale cerca de 1 hora), para abrir e exibir sem login no Monday.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, array{id: string, name: string, url: string, extension: string, size: int, image: bool}>
     */
    private function itemAssets(string $token, array $item): array
    {
        $raw = [...($item['assets'] ?? []), ...array_merge([], ...array_map(fn (array $update): array => $update['assets'] ?? [], $item['updates'] ?? []))];
        $bodies = collect($item['updates'] ?? [])->flatMap(fn (array $update): array => [$update['body'] ?? '', ...array_column($update['replies'] ?? [], 'body')]);
        $known = array_map(fn (array $asset): string => (string) $asset['id'], $raw);
        $missing = array_values(array_diff($this->assetIds($bodies->flatMap(fn (?string $body): array => $this->imageSources((string) $body))->implode(' ')), $known));
        if ($missing !== []) {
            try {
                $query = 'query ($ids: [ID!]!) { assets(ids: $ids) { id name file_extension file_size public_url url } }';
                array_push($raw, ...($this->query($token, $query, ['ids' => $missing])['assets'] ?? []));
            } catch (RuntimeException) {
                // Sem o link público, as imagens do corpo apenas não aparecem; o restante do card continua.
            }
        }

        $assets = [];
        foreach ($raw as $asset) {
            $extension = strtolower(ltrim((string) ($asset['file_extension'] ?? ''), '.'));
            $assets[(string) $asset['id']] = [
                'id' => (string) $asset['id'],
                'name' => (string) ($asset['name'] ?? 'arquivo'),
                'url' => (string) (($asset['public_url'] ?? '') ?: ($asset['url'] ?? '')),
                'extension' => $extension,
                'size' => (int) ($asset['file_size'] ?? 0),
                'image' => in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'], true),
            ];
        }

        return $assets;
    }

    /**
     * Endereços das imagens embutidas num comentário em HTML.
     *
     * @return list<string>
     */
    private function imageSources(string $html): array
    {
        preg_match_all('/<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $matches);

        return $matches[1];
    }

    /**
     * Ids de arquivos do Monday citados em links (`.../resources/{id}/arquivo`).
     *
     * @return list<string>
     */
    private function assetIds(string $text): array
    {
        preg_match_all('#/resources/(\d+)/#', $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Arquivos conhecidos citados num texto (links de coluna de arquivo ou imagens de comentário).
     *
     * @param  array<string, array<string, mixed>>  $assets
     * @return list<array<string, mixed>>
     */
    private function assetsIn(string $text, array $assets): array
    {
        return array_values(array_filter(array_map(fn (string $id): ?array => $assets[$id] ?? null, $this->assetIds($text))));
    }

    /**
     * Histórico de alterações do card (log de atividades do Monday), do mais recente para o mais antigo.
     * Comentários ficam de fora: já aparecem com o texto completo em itemDetails().
     *
     * @return list<array{id: string, event: string, author: string, createdAt: string, column: ?string, from: string, to: string}>
     */
    public function itemActivity(string $token, string $boardId, string $itemId): array
    {
        $query = 'query ($board: [ID!]!, $items: [ID!]) { boards(ids: $board) { activity_logs(item_ids: $items, limit: 100) { id event user_id created_at data } } }';
        $logs = $this->query($token, $query, ['board' => [$boardId], 'items' => [$itemId]])['boards'][0]['activity_logs'] ?? [];
        $logs = array_values(array_filter($logs, fn (array $log): bool => ! in_array($log['event'] ?? '', ['create_update', 'edit_update', 'delete_update', 'create_reply'], true)));

        $decoded = array_map(fn (array $log): array => json_decode($log['data'] ?? '', true) ?: [], $logs);
        $userIds = collect($logs)->pluck('user_id')->merge(collect($decoded)->flatMap(fn (array $data): array => [
            ...$this->personIds($data['value'] ?? null), ...$this->personIds($data['previous_value'] ?? null),
        ]))->filter()->map(fn (mixed $id): string => (string) $id)->unique()->values()->all();
        $names = [];
        if ($userIds !== []) {
            foreach ($this->query($token, 'query ($ids: [ID!]) { users(ids: $ids) { id name } }', ['ids' => $userIds])['users'] ?? [] as $user) {
                $names[(string) $user['id']] = (string) $user['name'];
            }
        }

        $activity = [];
        foreach ($logs as $index => $log) {
            $data = $decoded[$index];
            $activity[] = [
                'id' => (string) $log['id'],
                'event' => (string) $log['event'],
                'author' => $names[(string) ($log['user_id'] ?? '')] ?? 'Automação',
                // created_at do log vem em unidades de 100 ns desde a época Unix.
                'createdAt' => Carbon::createFromTimestampUTC(((int) $log['created_at']) / 10_000_000)->toIso8601String(),
                'column' => $data['column_title'] ?? null,
                'from' => $this->activityValue($data['previous_value'] ?? null, $names),
                'to' => $this->activityValue($data['value'] ?? ($data['name'] ?? null), $names),
            ];
        }

        return $activity;
    }

    /**
     * Troca o status do card pelo rótulo informado, que precisa existir na coluna de status do quadro.
     */
    public function changeStatus(string $token, string $boardId, string $itemId, string $statusColumnId, string $label): void
    {
        $query = 'query ($ids: [ID!]!, $columns: [String!]) { items(ids: $ids) { id board { id columns(ids: $columns) { id settings_str } } } }';
        $item = $this->boardItem($token, $boardId, $query, ['ids' => [$itemId], 'columns' => [$statusColumnId]]);
        $labels = array_column($this->statusOptions(collect($item['board']['columns'] ?? [])->firstWhere('id', $statusColumnId) ?? []), 'label');
        if (! in_array($label, $labels, true)) {
            throw new RuntimeException('Status inválido para este quadro.');
        }
        $mutation = 'mutation ($board: ID!, $item: ID!, $column: String!, $value: String!) { change_simple_column_value(board_id: $board, item_id: $item, column_id: $column, value: $value) { id } }';
        $this->query($token, $mutation, ['board' => $boardId, 'item' => $itemId, 'column' => $statusColumnId, 'value' => $label]);
    }

    /**
     * Publica um comentário no card. O Monday registra o dono do token como autor, por isso o texto leva quem enviou pelo dashboard.
     * Os anexos seguem depois, um por requisição (attachFile), para cada envio caber no limite de upload do servidor.
     */
    public function postComment(string $token, string $boardId, string $itemId, string $text, string $author): string
    {
        $this->boardItem($token, $boardId, 'query ($ids: [ID!]!) { items(ids: $ids) { id board { id } } }', ['ids' => [$itemId]]);
        $paragraphs = $text === '' ? '<p>Evidências anexadas.</p>' : '<p>'.nl2br(e($text), false).'</p>';
        $body = $paragraphs.'<p><em>Enviado por '.e($author).' pelo Dashboard YUV</em></p>';
        $update = $this->query($token, 'mutation ($item: ID!, $body: String!) { create_update(item_id: $item, body: $body) { id } }', ['item' => $itemId, 'body' => $body]);
        $updateId = (string) ($update['create_update']['id'] ?? '');
        if (! ctype_digit($updateId)) {
            throw new RuntimeException('O Monday não confirmou a criação do comentário.');
        }

        return $updateId;
    }

    /**
     * Anexa um arquivo a um comentário do card. O comentário precisa ser desse card e o card, do quadro configurado.
     */
    public function attachFile(string $token, string $boardId, string $itemId, string $updateId, UploadedFile $file): void
    {
        $query = 'query ($ids: [ID!]!, $updates: [ID!]) { items(ids: $ids) { id board { id } } updates(ids: $updates) { id item_id } }';
        $data = $this->query($token, $query, ['ids' => [$itemId], 'updates' => [$updateId]]);
        if ((string) ($data['items'][0]['board']['id'] ?? '') !== $boardId) {
            throw new RuntimeException('Card não encontrado no quadro configurado.');
        }
        if ((string) ($data['updates'][0]['item_id'] ?? '') !== $itemId) {
            throw new RuntimeException('Comentário não encontrado neste card.');
        }

        $response = Http::timeout(120)
            ->withHeaders(['Authorization' => $token, 'API-Version' => '2026-07'])
            ->attach('variables[file]', fopen($file->getRealPath(), 'r'), $file->getClientOriginalName())
            ->post('https://api.monday.com/v2/file', ['query' => 'mutation ($file: File!) { add_file_to_update(update_id: '.$updateId.', file: $file) { id } }']);
        if (! $response->successful() || ! empty($response->json('errors')) || empty($response->json('data.add_file_to_update.id'))) {
            throw new RuntimeException('Monday recusou o arquivo: '.($response->json('errors.0.message') ?? $response->json('error_message') ?? 'HTTP '.$response->status()).'.');
        }
    }

    /**
     * Card buscado pela consulta informada, recusando cards de outros quadros que o token também enxerga.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function boardItem(string $token, string $boardId, string $query, array $variables): array
    {
        $item = $this->query($token, $query, $variables)['items'][0] ?? null;
        if ($item === null || (string) ($item['board']['id'] ?? '') !== $boardId) {
            throw new RuntimeException('Card não encontrado no quadro configurado.');
        }

        return $item;
    }

    /**
     * Rótulos da coluna de status na ordem do Monday. Aceita o formato antigo (índice => texto) e o de lista.
     *
     * @param  array{settings_str?: string}  $column
     * @return list<array{label: string, color: ?string}>
     */
    public function statusOptions(array $column): array
    {
        $settings = json_decode($column['settings_str'] ?? '', true);
        $options = [];
        foreach (is_array($settings['labels'] ?? null) ? $settings['labels'] : [] as $index => $label) {
            $text = is_array($label) ? ($label['name'] ?? $label['label'] ?? null) : $label;
            $key = (string) (is_array($label) ? ($label['id'] ?? $index) : $index);
            if (! is_string($text) || trim($text) === '' || in_array((int) $key, $settings['deactivated_labels'] ?? [], true)) {
                continue;
            }
            $color = is_array($label) ? ($label['color'] ?? null) : ($settings['labels_colors'][$key]['color'] ?? null);
            $options[$key] = ['label' => $text, 'color' => is_string($color) && str_starts_with($color, '#') ? $color : null];
        }
        $positions = $settings['labels_positions_v2'] ?? [];
        uksort($options, fn (string $a, string $b): int => ($positions[$a] ?? (int) $a) <=> ($positions[$b] ?? (int) $b));

        return array_values($options);
    }

    /**
     * IDs de pessoas citadas num valor de coluna do log de atividades.
     *
     * @return list<string>
     */
    private function personIds(mixed $value): array
    {
        return is_array($value) ? array_map(fn (array $person): string => (string) $person['id'], array_filter($value['personsAndTeams'] ?? [], 'is_array')) : [];
    }

    /**
     * Valor legível de uma coluna no log de atividades (status, pessoas, números, datas, textos, vínculos).
     *
     * @param  array<string, string>  $names
     */
    private function activityValue(mixed $value, array $names): string
    {
        if (! is_array($value)) {
            return is_scalar($value) ? trim((string) $value) : '';
        }
        if (isset($value['label']['text'])) {
            return (string) $value['label']['text'];
        }
        if (isset($value['personsAndTeams'])) {
            return implode(', ', array_map(fn (string $id): string => $names[$id] ?? 'Equipe', $this->personIds($value)));
        }
        if (isset($value['linkedPulseIds'])) {
            $count = count($value['linkedPulseIds']);

            return $count === 1 ? '1 item vinculado' : "{$count} itens vinculados";
        }
        if (isset($value['from'], $value['to'])) {
            return "{$value['from']} → {$value['to']}";
        }
        if (isset($value['duration'])) {
            return round((float) $value['duration'] / 3600, 2).'h';
        }
        if (isset($value['checked'])) {
            return filter_var($value['checked'], FILTER_VALIDATE_BOOLEAN) ? 'Marcado' : 'Desmarcado';
        }
        foreach (['text', 'value', 'date', 'name', 'url'] as $key) {
            if (isset($value[$key]) && is_scalar($value[$key])) {
                return trim((string) $value[$key].(isset($value['time']) && $key === 'date' ? ' '.$value['time'] : ''));
            }
        }

        return '';
    }

    /**
     * Comentários do Monday vêm em HTML; o dashboard mostra só o texto, preservando as quebras de linha.
     */
    private function plainText(string $html): string
    {
        $text = preg_replace(['/<br\s*\/?>/i', '/<\/(p|div|li|h\d|tr)>/i', '/<li[^>]*>/i'], ["\n", "\n", '• '], $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\u{00A0}", ' ', $text)));
    }

    public function hours(array $item, ?string $columnId): ?float
    {
        $value = $this->column($item, $columnId);
        if ($value === null) {
            return null;
        }
        if (($value['type'] ?? '') === 'time_tracking' && isset($value['duration'])) {
            return round((float) $value['duration'] / 3600, 5);
        }
        $text = trim(str_replace(',', '.', (string) ($value['text'] ?? '')));
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(\d+)\s*h\s*(\d{1,2})?\s*m?$/i', $text, $matches)) {
            if ((int) ($matches[2] ?? 0) >= 60) {
                return null;
            }

            return (float) $matches[1] + (float) ($matches[2] ?? 0) / 60;
        }
        if (preg_match('/^(\d{1,2})\s*m$/i', $text, $matches)) {
            return (float) $matches[1] / 60;
        }
        if (preg_match('/^(\d+):(\d{2})$/', $text, $matches) && (int) $matches[2] < 60) {
            return (float) $matches[1] + (float) $matches[2] / 60;
        }

        return is_numeric($text) ? (float) $text : null;
    }

    private function column(array $item, ?string $columnId): ?array
    {
        if (! $columnId) {
            return null;
        }
        foreach ($item['column_values'] ?? [] as $column) {
            if (($column['id'] ?? null) === $columnId) {
                return $column;
            }
        }

        return null;
    }

    private function text(array $item, ?string $columnId): string
    {
        $value = $this->column($item, $columnId);

        return (string) (($value['type'] ?? '') === 'board_relation' && ! empty($value['display_value'])
            ? $value['display_value'] : ($value['text'] ?? ''));
    }

    private function flag(string $value): int
    {
        return (int) (bool) preg_match('/^(v|x|sim|yes|true|1|checked)$/i', trim($value));
    }

    /**
     * Datas de cada sprint e o número da sprint de cada item do quadro de sprints (usado para ler o log de atividades).
     *
     * @return array{dates: list<array{number: int, name: string, start: string, end: string, source: string}>, items: array<string, int>}
     */
    private function sprintDates(array $taskBoard, string $token, string $sprintColumnId): array
    {
        $column = collect($taskBoard['columns'] ?? [])->firstWhere('id', $sprintColumnId);
        $settings = json_decode($column['settings_str'] ?? '{}', true);
        $boardId = $settings['boardIds'][0] ?? null;
        if (! $boardId) {
            return ['dates' => [], 'items' => []];
        }
        $query = 'query ($board: [ID!]!) { boards(ids: $board) { items_page(limit: 500) { items { id name column_values { id type ... on TimelineValue { from to } } } } } }';
        $data = $this->query($token, $query, ['board' => [(string) $boardId]]);
        $dates = [];
        $sprintItems = [];
        foreach ($data['boards'][0]['items_page']['items'] ?? [] as $item) {
            if (! preg_match('/\bSprint\s*(\d+)\b/iu', $item['name'], $matches)) {
                continue;
            }
            $sprintItems[(string) $item['id']] = (int) $matches[1];
            foreach ($item['column_values'] ?? [] as $value) {
                if (($value['type'] ?? '') === 'timeline' && ! empty($value['from']) && ! empty($value['to'])) {
                    $dates[] = [
                        'number' => (int) $matches[1], 'name' => $item['name'],
                        'start' => substr($value['from'], 0, 10), 'end' => substr($value['to'], 0, 10),
                        'source' => 'Cronograma do sprint no Monday',
                    ];
                    break;
                }
            }
        }

        return ['dates' => $dates, 'items' => $sprintItems];
    }
}
