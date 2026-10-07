<?php

namespace App\Services;

use App\Models\MondayConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Snapshot dos cards do Monday compartilhado pelo dashboard, pela Torre de controle e pelo comando agendado.
 */
class MondaySnapshotService
{
    public const CACHE_KEY = 'monday-sync-1';

    /** Indica se a última chamada de current() buscou dados novos no Monday (em vez de usar o cache). */
    public bool $fetchedFresh = false;

    public function __construct(private MondayService $monday) {}

    /**
     * Estado atual dos cards, reaproveitando o cache enquanto tiver menos de $ttlSeconds.
     *
     * @return array<string, mixed>
     */
    public function current(MondayConnection $connection, int $ttlSeconds, bool $force = false): array
    {
        if (! $connection->board_id || ! $connection->token || empty($connection->column_map['Sprint'])) {
            throw new RuntimeException('Configure o quadro, token e coluna Sprint antes de atualizar.');
        }
        $this->fetchedFresh = false;
        if (! $force && ($cached = Cache::get(self::CACHE_KEY))) {
            $age = Carbon::parse($cached['syncedAt'])->diffInSeconds(now(), false);
            if ($age >= 0 && $age < $ttlSeconds) {
                return $cached;
            }
        }
        $result = $this->monday->sync($connection->token, $connection->board_id, $connection->column_map ?? []);
        Cache::put(self::CACHE_KEY, $result, 3600);
        $this->fetchedFresh = true;

        return $result;
    }

    /**
     * Snapshot com as sprints encerradas congeladas no estado do último dia (ver SprintTimeline::frozenRows).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function withFrozenSprints(array $snapshot, MondayConnection $connection): array
    {
        return Cache::remember('monday-sync-frozen-'.sha1((string) $snapshot['syncedAt']), 3600,
            fn (): array => [...$snapshot, 'rows' => SprintTimeline::fromDatabase($snapshot, $connection)->frozenRows()]);
    }
}
