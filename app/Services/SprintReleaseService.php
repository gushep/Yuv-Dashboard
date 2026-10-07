<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class SprintReleaseService
{
    public function __construct(private MondayService $monday, private ReleaseSummaryService $summaries) {}

    /**
     * @return array{sprint: ?int, sprints: array, dates: ?array, total: int, groups: list<array{type: string, items: array, count: int}>, syncedAt: string, commentsUnavailable: int, summaryMethod: string}
     */
    public function build(array $snapshot, ?int $sprint, string $token, string $boardId): array
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
        foreach (array_keys($items) as $id) {
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
        $groups = [];
        $unavailable = 0;
        foreach ($items as $id => $row) {
            $source = $comments[$id] ?? ['available' => false, 'comments' => []];
            $unavailable += (int) ! $source['available'];
            $type = trim((string) ($row['Tipo'] ?? ''));
            $type = $type !== '' ? $type : 'Sem tipo';
            $description = null;
            if ($source['available']) {
                try {
                    $description = $this->summaries->summarize((string) $id, $source['comments'], [(string) ($row['Responsavel'] ?? '')]);
                } catch (\Throwable) {
                    $description = null;
                }
            }
            $groups[$type] ??= ['type' => $type, 'items' => []];
            $groups[$type]['items'][] = [
                'id' => (string) $id, 'title' => $row['Nome'], 'description' => $description,
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
            'commentsUnavailable' => $unavailable, 'summaryMethod' => 'conservative-extraction',
        ];
    }

    private function safeUrl(string $value): ?string
    {
        $host = parse_url($value, PHP_URL_HOST);

        return parse_url($value, PHP_URL_SCHEME) === 'https' && is_string($host)
            && ($host === 'monday.com' || str_ends_with($host, '.monday.com')) ? $value : null;
    }
}
