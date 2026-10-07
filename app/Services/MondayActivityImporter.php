<?php

namespace App\Services;

use App\Models\MondayActivityEvent;
use App\Models\MondayConnection;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Copia o log de atividades do Monday para o banco. O Monday descarta eventos antigos;
 * a cópia preserva o histórico (status, sprint, horas, estimativas) para reconstituir qualquer dia da sprint.
 */
class MondayActivityImporter
{
    private const PAGE_SIZE = 500;

    private const MAX_PAGES = 400;

    /** Margem para eventos registrados no Monday com atraso em relação à última cópia. */
    private const OVERLAP_MINUTES = 10;

    public function __construct(private MondayService $monday) {}

    /**
     * Na primeira execução copia tudo o que o Monday ainda retém; depois, só o que for novo.
     *
     * @return array{imported: int, from: ?string}
     */
    public function import(MondayConnection $connection): array
    {
        $boardId = (string) $connection->board_id;
        $token = (string) $connection->token;
        $latest = MondayActivityEvent::query()->where('board_id', $boardId)->max('occurred_at');
        $from = $latest ? Carbon::parse($latest)->subMinutes(self::OVERLAP_MINUTES)->toIso8601ZuluString() : null;
        $to = now()->toIso8601ZuluString();
        $imported = 0;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $logs = $this->monday->activityLogs($token, $boardId, $from, $to, $page, self::PAGE_SIZE);
            $rows = [];
            foreach ($logs as $log) {
                array_push($rows, ...$this->rows($log, $boardId));
            }
            if ($rows !== []) {
                $imported += MondayActivityEvent::query()->insertOrIgnore($rows);
            }
            if (count($logs) < self::PAGE_SIZE) {
                return ['imported' => $imported, 'from' => $from];
            }
        }

        throw new RuntimeException('O log de atividades ultrapassou '.self::MAX_PAGES.' páginas.');
    }

    /**
     * Uma linha por card afetado; eventos do quadro (sem card) usam item_id vazio.
     *
     * @param  array{id: string, event: string, user_id: ?string, created_at: string, data: string}  $log
     * @return list<array<string, mixed>>
     */
    private function rows(array $log, string $boardId): array
    {
        $data = json_decode($log['data'] ?? '', true) ?: [];
        $itemIds = $data['pulse_ids'] ?? (isset($data['pulse_id']) ? [$data['pulse_id']] : ['']);
        $now = now();
        $json = fn (mixed $value): ?string => $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE);

        return array_map(fn (int|string $itemId): array => [
            'monday_id' => (string) $log['id'],
            'board_id' => $boardId,
            'item_id' => (string) $itemId,
            'event' => (string) $log['event'],
            'column_id' => $data['column_id'] ?? null,
            'column_title' => $data['column_title'] ?? null,
            'previous_value' => $json($data['previous_value'] ?? null),
            'value' => $json($data['value'] ?? null),
            'user_id' => isset($log['user_id']) ? (string) $log['user_id'] : null,
            'data' => $json($data),
            // created_at do Monday vem em unidades de 100 ns desde a época Unix.
            'occurred_at' => Carbon::createFromTimestampUTC(((int) $log['created_at']) / 10_000_000)->format('Y-m-d H:i:s.u'),
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values(array_unique(array_map('strval', $itemIds))));
    }
}
