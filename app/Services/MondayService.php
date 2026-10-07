<?php

namespace App\Services;

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
        foreach ($board['columns'] ?? [] as $column) {
            if (in_array(mb_strtolower(trim($column['title'] ?? '')), ['módulo', 'modulo'], true)) {
                $map['Modulo'] = $column['id'];
                break;
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
