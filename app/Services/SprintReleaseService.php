<?php

namespace App\Services;

use App\Models\ReleaseSummary;
use Illuminate\Support\Facades\Cache;

class SprintReleaseService
{
    public function __construct(private MondayService $monday, private ReleaseSummaryService $summaries) {}

    /**
     * Release da sprint. A descrição de cada card vem, nesta ordem: da coluna Descrição do Monday, do OBJETIVO da ficha
     * de especificação, do resumo gerado no Claude Code (marcado como desatualizado se o card mudou) ou da extração conservadora dos comentários.
     *
     * @return array{sprint: ?int, sprints: array, dates: ?array, total: int, groups: list<array{type: string, items: array, count: int}>, syncedAt: string, commentsUnavailable: int, withoutDescription: int, staleSummaries: int, summaryMethod: string}
     */
    public function build(array $snapshot, ?int $sprint, string $token, string $boardId): array
    {
        ['sprint' => $sprint, 'sprints' => $sprints, 'items' => $items, 'comments' => $comments] = $this->load($snapshot, $sprint, $token, $boardId);
        $stored = ReleaseSummary::query()->whereIn('item_id', array_map('strval', array_keys($items)))->get()->keyBy('item_id');
        $groups = [];
        $unavailable = $withoutDescription = $stale = 0;
        foreach ($items as $id => $row) {
            $source = $comments[$id] ?? ['available' => false, 'comments' => []];
            $unavailable += (int) (! $source['available'] && ! $this->hasMondayDescription($row));
            $type = trim((string) ($row['Tipo'] ?? ''));
            $type = $type !== '' ? $type : 'Sem tipo';
            [$description, $origin, $isStale] = $this->describe($row, $source, $stored->get((string) $id));
            $withoutDescription += (int) ($description === null);
            $stale += (int) $isStale;
            $groups[$type] ??= ['type' => $type, 'items' => []];
            $groups[$type]['items'][] = [
                'id' => (string) $id, 'title' => $row['Nome'], 'description' => $description,
                'descriptionSource' => $origin, 'descriptionStale' => $isStale,
                'module' => filled($row['Modulo'] ?? null) ? trim($row['Modulo']) : null,
                'url' => $this->safeUrl((string) ($row['Url'] ?? '')),
            ];
        }
        foreach ($groups as &$group) {
            usort($group['items'], fn (array $first, array $second): int => strnatcasecmp($first['title'], $second['title']));
            $group['count'] = count($group['items']);
        }
        unset($group);
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'sprint' => $sprint, 'sprints' => $sprints, 'dates' => collect($snapshot['sprints'] ?? [])->firstWhere('number', $sprint),
            'total' => count($items), 'groups' => array_values($groups), 'syncedAt' => $snapshot['syncedAt'],
            'commentsUnavailable' => $unavailable, 'withoutDescription' => $withoutDescription, 'staleSummaries' => $stale,
            'summaryMethod' => 'monday-ficha-resumo-extracao',
        ];
    }

    /**
     * Cards implementados que precisam de resumo: sem Descrição no Monday, sem ficha preenchida e sem resumo atualizado
     * (ou todos os sem Descrição no Monday, com $all).
     *
     * @return list<array{id: string, title: string, type: string, module: ?string, hash: string, currentSummary: ?string, text: string}>
     */
    public function pendingSummaries(array $snapshot, int $sprint, string $token, string $boardId, bool $all = false): array
    {
        ['items' => $items, 'comments' => $comments] = $this->load($snapshot, $sprint, $token, $boardId);
        $stored = ReleaseSummary::query()->whereIn('item_id', array_map('strval', array_keys($items)))->get()->keyBy('item_id');
        $pending = [];
        foreach ($items as $id => $row) {
            $source = $comments[$id] ?? ['available' => false, 'comments' => []];
            if (! $source['available'] || $this->hasMondayDescription($row)) {
                continue;
            }
            $hash = $this->summaries->sourceHash((string) $row['Nome'], $source['comments']);
            $summary = $stored->get((string) $id);
            $needsSummary = $this->summaries->objective($source['comments']) === null && ($summary === null || $summary->source_hash !== $hash);
            if ($all || $needsSummary) {
                $pending[] = [
                    'id' => (string) $id, 'title' => (string) $row['Nome'], 'type' => (string) ($row['Tipo'] ?? ''),
                    'module' => filled($row['Modulo'] ?? null) ? trim($row['Modulo']) : null, 'hash' => $hash,
                    'currentSummary' => $summary?->summary, 'text' => $this->summaries->sourceText((string) $row['Nome'], $source['comments']),
                ];
            }
        }

        return $pending;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: ?string, 1: ?string, 2: bool} descrição, origem (monday|ficha|resumo|comentario) e se o resumo está desatualizado
     */
    private function describe(array $row, array $source, ?ReleaseSummary $stored): array
    {
        if ($this->hasMondayDescription($row)) {
            return [trim((string) $row['Descricao']), 'monday', false];
        }
        $id = (string) $row['Id'];
        $title = (string) $row['Nome'];
        $owner = (string) ($row['Responsavel'] ?? '');
        if ($source['available'] && ($objective = $this->summaries->objective($source['comments'])) !== null) {
            return [$objective, 'ficha', false];
        }
        if ($stored !== null) {
            $stale = $source['available'] && $stored->source_hash !== $this->summaries->sourceHash($title, $source['comments']);

            return [$stored->summary, 'resumo', $stale];
        }
        if ($source['available']) {
            try {
                $description = $this->summaries->summarize($id, $source['comments'], [$owner]);
            } catch (\Throwable) {
                $description = null;
            }
            if ($description !== null) {
                return [$description, 'comentario', false];
            }
        }

        return [null, null, false];
    }

    /**
     * Cards implementados da sprint e seus comentários (comentários em cache por 2 minutos por sincronização).
     *
     * @return array{sprint: ?int, sprints: list<int>, items: array<string, array<string, mixed>>, comments: array<string, array{available: bool, comments: array}>}
     */
    private function load(array $snapshot, ?int $sprint, string $token, string $boardId): array
    {
        $sprints = array_values(array_unique([
            ...array_column($snapshot['rows'], 'Sprint'),
            ...array_column($snapshot['sprints'] ?? [], 'number'),
        ]));
        rsort($sprints, SORT_NUMERIC);
        $sprint ??= $sprints[0] ?? null;
        $items = [];
        foreach ($snapshot['rows'] as $row) {
            $id = trim((string) ($row['Id'] ?? ''));
            $status = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) ($row['Status'] ?? '')) ?? ''));
            if ($sprint !== null && (int) $row['Sprint'] === $sprint && $status === 'implementado' && $id !== '') {
                $items[$id] ??= $row;
            }
        }
        $scope = hash('sha256', $boardId.'|'.$token.'|'.$snapshot['syncedAt']);
        $comments = [];
        $missing = [];
        foreach ($items as $id => $row) {
            if ($this->hasMondayDescription($row)) {
                continue;
            }
            $cached = Cache::get('release-comments-'.$scope.'-'.$id);
            if ($cached === null) {
                $missing[] = (string) $id;
            } else {
                $comments[$id] = $cached;
            }
        }
        if ($missing !== []) {
            $loaded = $this->monday->releaseComments($token, $missing);
            foreach ($loaded as $id => $value) {
                $comments[$id] = $value;
                if ($value['available']) {
                    Cache::put('release-comments-'.$scope.'-'.$id, $value, 120);
                }
            }
        }

        return ['sprint' => $sprint, 'sprints' => $sprints, 'items' => $items, 'comments' => $comments];
    }

    /**
     * Card com a coluna Descrição preenchida no Monday: dispensa ficha, resumo e leitura de comentários.
     *
     * @param  array<string, mixed>  $row
     */
    private function hasMondayDescription(array $row): bool
    {
        return trim((string) ($row['Descricao'] ?? '')) !== '';
    }

    private function safeUrl(string $value): ?string
    {
        $host = parse_url($value, PHP_URL_HOST);

        return parse_url($value, PHP_URL_SCHEME) === 'https' && is_string($host)
            && ($host === 'monday.com' || str_ends_with($host, '.monday.com')) ? $value : null;
    }
}
