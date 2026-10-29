<?php

namespace App\Services;

use App\Models\MondayConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Snapshot dos cards do Monday (e dos quadros das abas Roadmap e Backlog) compartilhado pelo dashboard, pela Torre de
 * controle e pelo comando agendado. As telas leem o último snapshot salvo sem esperar o Monday; quando ele está vencido,
 * a atualização roda depois da resposta. Uma trava garante uma busca por vez no Monday, por mais telas abertas que haja.
 */
class MondaySnapshotService
{
    public const CACHE_KEY = 'monday-sync-1';

    /** Abas da tela Tarefas que mostram outros quadros do Monday, e a coluna de configuração de cada uma. */
    public const BOARD_TABS = ['roadmap' => 'roadmap_board_id', 'backlog' => 'backlog_board_id'];

    /** Prefixo do cache de cada aba de quadro. */
    public const BOARD_TAB_CACHE = 'monday-board-tab-';

    /** Uma busca no Monday leva uns 12 segundos; a trava expira sozinha se o processo morrer no meio. */
    private const LOCK_SECONDS = 120;

    /** Quanto quem precisa do dado na hora espera outra busca em andamento terminar. */
    private const WAIT_SECONDS = 90;

    /** Indica se a última leitura buscou dados novos no Monday (em vez de usar o cache). */
    public bool $fetchedFresh = false;

    /** Indica se a última leitura devolveu dados vencidos, que precisam ser atualizados depois da resposta. */
    public bool $stale = false;

    public function __construct(private MondayService $monday) {}

    /**
     * Estado atual dos cards, esperando o Monday quando o cache tem $ttlSeconds ou mais (ou com $force).
     * Para o comando agendado e o botão "Atualizar dados agora"; as telas usam latest().
     *
     * @return array<string, mixed>
     */
    public function current(MondayConnection $connection, int $ttlSeconds, bool $force = false): array
    {
        $this->ensureConfigured($connection);
        $this->fetchedFresh = false;
        $this->stale = false;
        [$cached, $stale] = $this->cached(self::CACHE_KEY, $ttlSeconds);
        if (! $force && $cached !== null && ! $stale) {
            return $cached;
        }

        return $this->fetch(self::CACHE_KEY, $this->snapshotLoader($connection));
    }

    /**
     * Último snapshot salvo, sem esperar o Monday. Vencido (mais de $ttlSeconds ou marcado por uma mudança feita pelo
     * dashboard), volta assim mesmo com $stale ligado, para quem chamou atualizar depois da resposta. Só espera o Monday
     * quando ainda não há nenhum snapshot salvo.
     *
     * @return array<string, mixed>
     */
    public function latest(MondayConnection $connection, int $ttlSeconds): array
    {
        $this->ensureConfigured($connection);
        $this->fetchedFresh = false;
        [$cached, $this->stale] = $this->cached(self::CACHE_KEY, $ttlSeconds);
        if ($cached !== null) {
            return $cached;
        }
        $this->stale = false;

        return $this->fetch(self::CACHE_KEY, $this->snapshotLoader($connection));
    }

    /**
     * Atualiza o snapshot vencido em segundo plano. Não espera: se outra busca já estiver em andamento, ou se o cache
     * tiver sido atualizado nesse meio-tempo, não faz nada.
     *
     * @return ?array<string, mixed> o snapshot novo, ou null quando não buscou
     */
    public function refreshInBackground(MondayConnection $connection, int $ttlSeconds): ?array
    {
        $this->fetchedFresh = false;

        return $this->fetchIfStale(self::CACHE_KEY, $ttlSeconds, $this->snapshotLoader($connection));
    }

    /**
     * Itens do quadro de uma aba (Roadmap ou Backlog), com a mesma regra de latest(): o cache salvo na hora, com $stale
     * ligado quando vencido. Com $force (comando agendado), espera o Monday.
     *
     * @return array<string, mixed>
     */
    public function boardTab(MondayConnection $connection, string $tab, int $ttlSeconds, bool $force = false): array
    {
        $this->stale = false;
        $boardId = (string) $connection->{self::BOARD_TABS[$tab]};
        if ($boardId === '') {
            return ['configured' => false];
        }
        [$cached, $stale] = $this->cached(self::BOARD_TAB_CACHE.$tab, $ttlSeconds, $boardId);
        if ($cached !== null && ! $force) {
            $this->stale = $stale;

            return ['configured' => true, ...$cached];
        }

        return ['configured' => true, ...$this->fetch(self::BOARD_TAB_CACHE.$tab, $this->boardTabLoader($connection, $boardId))];
    }

    /**
     * Atualiza em segundo plano o quadro vencido de uma aba, com a mesma regra de refreshInBackground().
     */
    public function refreshBoardTabInBackground(MondayConnection $connection, string $tab, int $ttlSeconds): void
    {
        $boardId = (string) $connection->{self::BOARD_TABS[$tab]};
        if ($boardId !== '') {
            $this->fetchIfStale(self::BOARD_TAB_CACHE.$tab, $ttlSeconds, $this->boardTabLoader($connection, $boardId), $boardId);
        }
    }

    /**
     * Marca o snapshot e os quadros das abas como desatualizados depois de uma mudança feita pelo dashboard (status,
     * alteração em massa, ticket novo). As telas continuam abrindo na hora, e a próxima leitura atualiza em segundo plano.
     */
    public function markStale(): void
    {
        foreach ([self::CACHE_KEY, ...array_map(fn (string $tab): string => self::BOARD_TAB_CACHE.$tab, array_keys(self::BOARD_TABS))] as $key) {
            Cache::forever($key.'-stale', now()->toIso8601String());
        }
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

    private function ensureConfigured(MondayConnection $connection): void
    {
        if (! $connection->board_id || ! $connection->token || empty($connection->column_map['Sprint'])) {
            throw new RuntimeException('Configure o quadro, token e coluna Sprint antes de atualizar.');
        }
    }

    /**
     * Cache salvo e se ele está vencido. Cache de outro quadro (a aba foi trocada nas Configurações) não vale.
     *
     * @return array{0: ?array<string, mixed>, 1: bool}
     */
    private function cached(string $key, int $ttlSeconds, ?string $boardId = null): array
    {
        $cached = Cache::get($key);
        if (! is_array($cached) || ! isset($cached['syncedAt']) || ($boardId !== null && ($cached['boardId'] ?? null) !== $boardId)) {
            return [null, false];
        }
        $age = Carbon::parse($cached['syncedAt'])->diffInSeconds(now(), false);

        return [$cached, $age < 0 || $age >= $ttlSeconds || Cache::has($key.'-stale')];
    }

    /**
     * Busca no Monday com a trava. Quem chega com outra busca em andamento espera ela terminar e aproveita o que ela
     * gravou, em vez de repetir a consulta.
     *
     * @param  callable(): array<string, mixed>  $load
     * @return array<string, mixed>
     */
    private function fetch(string $key, callable $load): array
    {
        $requestedAt = now();

        return Cache::lock($key.'-lock', self::LOCK_SECONDS)->block(self::WAIT_SECONDS, function () use ($key, $load, $requestedAt): array {
            $cached = Cache::get($key);
            if (is_array($cached) && isset($cached['syncedAt']) && Carbon::parse($cached['syncedAt'])->gte($requestedAt)) {
                return $cached;
            }

            return $this->store($key, $load);
        });
    }

    /**
     * Busca em segundo plano: sem esperar a trava, e só se o cache continuar vencido.
     *
     * @param  callable(): array<string, mixed>  $load
     * @return ?array<string, mixed>
     */
    private function fetchIfStale(string $key, int $ttlSeconds, callable $load, ?string $boardId = null): ?array
    {
        $result = Cache::lock($key.'-lock', self::LOCK_SECONDS)->get(function () use ($key, $ttlSeconds, $load, $boardId): ?array {
            [$cached, $stale] = $this->cached($key, $ttlSeconds, $boardId);

            return $cached !== null && ! $stale ? null : $this->store($key, $load);
        });

        return is_array($result) ? $result : null;
    }

    /**
     * Grava o resultado sem prazo de validade: a tela sempre tem o último snapshot para mostrar, mesmo com o Monday fora.
     * A marca de desatualizado só sai se for anterior ao início da busca (uma mudança feita durante a busca continua valendo).
     *
     * @param  callable(): array<string, mixed>  $load
     * @return array<string, mixed>
     */
    private function store(string $key, callable $load): array
    {
        $startedAt = now();
        $result = $load();
        Cache::forever($key, $result);
        $marked = Cache::get($key.'-stale');
        if ($marked !== null && Carbon::parse($marked)->lte($startedAt)) {
            Cache::forget($key.'-stale');
        }
        $this->fetchedFresh = true;

        return $result;
    }

    /**
     * @return callable(): array<string, mixed>
     */
    private function snapshotLoader(MondayConnection $connection): callable
    {
        return fn (): array => $this->monday->sync((string) $connection->token, (string) $connection->board_id, $connection->column_map ?? []);
    }

    /**
     * @return callable(): array<string, mixed>
     */
    private function boardTabLoader(MondayConnection $connection, string $boardId): callable
    {
        return fn (): array => ['boardId' => $boardId, ...$this->monday->boardTab((string) $connection->token, $boardId)];
    }
}
