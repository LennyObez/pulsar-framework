<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Storage;

use Closure;

use function dirname;
use function hash;
use function implode;
use function is_array;
use function is_dir;
use function is_int;
use function is_string;
use function mb_strtolower;
use function mkdir;

use PDO;
use PDOException;
use Pulsar\Api\Internal;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Studio\Console\Event\EventEnvelope;
use Pulsar\Studio\Exception\StudioException;

use function random_int;
use function sprintf;
use function str_contains;

use Throwable;

use function usleep;

/**
 * SQLite-backed event store with WAL mode and write contention handling.
 *
 * Uses BEGIN IMMEDIATE for serialized chain writes and exponential
 * backoff retry for SQLITE_BUSY/LOCKED errors.
 */
#[Internal]
final class SqliteEventStore implements EventStoreInterface
{
    private const int MAX_RETRY_ATTEMPTS = 5;
    private const int BASE_DELAY_MS = 5;
    private const float DELAY_MULTIPLIER = 3.0;

    private readonly PDO $pdo;

    public function __construct(
        string $storagePath,
        private readonly ?MetricRegistry $metricRegistry = null,
    ) {
        if ($storagePath !== ':memory:') {
            $dir = dirname($storagePath);

            if (!is_dir($dir)) {
                mkdir($dir, 0o755, true);
            }
        }

        $this->pdo = new PDO('sqlite:' . $storagePath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        SchemaManager::ensureSchema($this->pdo);
    }

    /**
     * Create a store using an in-memory SQLite database (for testing).
     */
    public static function inMemory(?MetricRegistry $metricRegistry = null): self
    {
        return new self(':memory:', $metricRegistry);
    }

    public function store(EventEnvelope $envelope, string $payloadJson, ?string $tenantHash = null): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
                INSERT INTO studio_events (
                    event_id, event_type, schema_version, timestamp_us,
                    request_id, trace_id, span_id, job_id,
                    app_env, hostname, tenant_hash,
                    payload_json, payload_hash, ciphertext_hash
                ) VALUES (
                    :event_id, :event_type, :schema_version, :timestamp_us,
                    :request_id, :trace_id, :span_id, :job_id,
                    :app_env, :hostname, :tenant_hash,
                    :payload_json, :payload_hash, :ciphertext_hash
                )
            SQL);

        $stmt->execute([
            'event_id' => $envelope->eventId,
            'event_type' => $envelope->eventType->value,
            'schema_version' => $envelope->schemaVersion->value,
            'timestamp_us' => $envelope->timestampUs,
            'request_id' => $envelope->requestId,
            'trace_id' => $envelope->traceId,
            'span_id' => $envelope->spanId,
            'job_id' => $envelope->jobId,
            'app_env' => $envelope->appEnv,
            'hostname' => $envelope->hostname,
            'tenant_hash' => $tenantHash,
            'payload_json' => $payloadJson,
            'payload_hash' => $envelope->payloadHash,
            'ciphertext_hash' => null,
        ]);
    }

    /**
     * Store event with chain link in an atomic transaction with retry.
     *
     * Uses BEGIN IMMEDIATE to acquire write lock before reading chain tip.
     * Retries on SQLITE_BUSY/LOCKED with exponential backoff.
     */
    public function storeWithChain(
        EventEnvelope $envelope,
        string $payloadJson,
        ?string $tenantHash,
        ?string $chainMacKey,
    ): void {
        $this->ingestWithRetry(function () use ($envelope, $payloadJson, $tenantHash, $chainMacKey): void {
            $this->pdo->exec('BEGIN IMMEDIATE');

            try {
                // Store the event
                $this->store($envelope, $payloadJson, $tenantHash);

                // Read current chain tip
                $tipStmt = $this->pdo->query(
                    'SELECT current_hash FROM studio_chain ORDER BY id DESC LIMIT 1',
                );
                /** @var array{current_hash: string}|false $tipRow */
                $tipRow = $tipStmt !== false ? $tipStmt->fetch() : false;
                $previousHash = $tipRow !== false
                    ? $tipRow['current_hash']
                    : hash('sha256', 'PULSAR_STUDIO_CHAIN_SEED');

                // Compute chain hash
                $currentHash = hash('sha256', $previousHash . '|' . $envelope->canonical());

                // Compute optional per-link MAC
                $linkMac = null;
                if ($chainMacKey !== null) {
                    $linkMac = Hmac::computeHex($currentHash, $chainMacKey);
                }

                // Insert chain link
                $chainStmt = $this->pdo->prepare(<<<'SQL'
                        INSERT INTO studio_chain (event_id, previous_hash, current_hash, link_mac)
                        VALUES (:event_id, :previous_hash, :current_hash, :link_mac)
                    SQL);

                $chainStmt->execute([
                    'event_id' => $envelope->eventId,
                    'previous_hash' => $previousHash,
                    'current_hash' => $currentHash,
                    'link_mac' => $linkMac,
                ]);

                $this->pdo->exec('COMMIT');
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->exec('ROLLBACK');
                }

                throw $e;
            }
        });
    }

    public function query(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $bindings = [];

        if (isset($filters['event_type'])) {
            $where[] = 'event_type = :event_type';
            /** @var string $eventTypeFilter */
            $eventTypeFilter = $filters['event_type'];
            $bindings['event_type'] = $eventTypeFilter;
        }

        if (isset($filters['request_id'])) {
            $where[] = 'request_id = :request_id';
            /** @var string $requestIdFilter */
            $requestIdFilter = $filters['request_id'];
            $bindings['request_id'] = $requestIdFilter;
        }

        if (isset($filters['trace_id'])) {
            $where[] = 'trace_id = :trace_id';
            /** @var string $traceIdFilter */
            $traceIdFilter = $filters['trace_id'];
            $bindings['trace_id'] = $traceIdFilter;
        }

        if (isset($filters['job_id'])) {
            $where[] = 'job_id = :job_id';
            /** @var string $jobIdFilter */
            $jobIdFilter = $filters['job_id'];
            $bindings['job_id'] = $jobIdFilter;
        }

        if (isset($filters['tenant_hash'])) {
            $where[] = 'tenant_hash = :tenant_hash';
            /** @var string $tenantHashFilter */
            $tenantHashFilter = $filters['tenant_hash'];
            $bindings['tenant_hash'] = $tenantHashFilter;
        }

        if (isset($filters['since_us'])) {
            $where[] = 'timestamp_us >= :since_us';
            /** @var int $sinceUsFilter */
            $sinceUsFilter = $filters['since_us'];
            $bindings['since_us'] = $sinceUsFilter;
        }

        if (isset($filters['until_us'])) {
            $where[] = 'timestamp_us <= :until_us';
            /** @var int $untilUsFilter */
            $untilUsFilter = $filters['until_us'];
            $bindings['until_us'] = $untilUsFilter;
        }

        if (isset($filters['since_id'])) {
            $where[] = 'id > :since_id';
            /** @var int $sinceIdFilter */
            $sinceIdFilter = $filters['since_id'];
            $bindings['since_id'] = $sinceIdFilter;
        }

        $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = sprintf(
            'SELECT * FROM studio_events %s ORDER BY timestamp_us DESC LIMIT :limit OFFSET :offset',
            $whereClause,
        );

        $stmt = $this->pdo->prepare($sql);

        foreach ($bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll();
    }

    public function count(array $filters = []): int
    {
        $where = [];
        $bindings = [];

        if (isset($filters['event_type'])) {
            $where[] = 'event_type = :event_type';
            /** @var string $eventTypeFilter */
            $eventTypeFilter = $filters['event_type'];
            $bindings['event_type'] = $eventTypeFilter;
        }

        if (isset($filters['since_us'])) {
            $where[] = 'timestamp_us >= :since_us';
            /** @var int $sinceUsFilter */
            $sinceUsFilter = $filters['since_us'];
            $bindings['since_us'] = $sinceUsFilter;
        }

        if (isset($filters['until_us'])) {
            $where[] = 'timestamp_us <= :until_us';
            /** @var int $untilUsFilter */
            $untilUsFilter = $filters['until_us'];
            $bindings['until_us'] = $untilUsFilter;
        }

        $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = sprintf('SELECT COUNT(*) FROM studio_events %s', $whereClause);

        $stmt = $this->pdo->prepare($sql);
        foreach ($bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function find(string $eventId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM studio_events WHERE event_id = :event_id');
        $stmt->execute(['event_id' => $eventId]);

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function sizeInBytes(): int
    {
        $stmt = $this->pdo->query('SELECT page_count * page_size AS size FROM pragma_page_count(), pragma_page_size()');

        if ($stmt === false) {
            return 0;
        }

        /** @var array{size: int|string}|false $row */
        $row = $stmt->fetch();

        return $row !== false ? (int) $row['size'] : 0;
    }

    public function deleteOlderThan(int $timestampUs): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM studio_events WHERE timestamp_us < :timestamp_us');
        $stmt->execute(['timestamp_us' => $timestampUs]);

        return $stmt->rowCount();
    }

    public function clear(): void
    {
        $this->pdo->exec('DELETE FROM studio_events');
        $this->pdo->exec('DELETE FROM studio_chain');
    }

    public function vacuum(): void
    {
        $this->pdo->exec('VACUUM');
    }

    /**
     * Get the underlying PDO connection (for SchemaManager and testing).
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Get chain links for verification.
     *
     * @return list<array<string, mixed>>
     */
    public function chainLinks(int $limit = 0, int $offset = 0): array
    {
        $sql = 'SELECT c.*, e.event_id as evt_id, e.event_type, e.schema_version, e.timestamp_us, e.trace_id, e.payload_hash '
            . 'FROM studio_chain c '
            . 'JOIN studio_events e ON c.event_id = e.event_id '
            . 'ORDER BY c.id ASC';

        if ($limit > 0) {
            $sql .= sprintf(' LIMIT %d OFFSET %d', $limit, $offset);
        }

        $stmt = $this->pdo->query($sql);

        if ($stmt === false) {
            return [];
        }

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll();
    }

    /**
     * Get a metadata value from studio_meta.
     */
    public function getMeta(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM studio_meta WHERE key = :key');
        $stmt->execute(['key' => $key]);

        /** @var string|false $value */
        $value = $stmt->fetchColumn();

        return $value !== false ? $value : null;
    }

    /**
     * Set a metadata value in studio_meta.
     */
    public function setMeta(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO studio_meta (key, value) VALUES (:key, :value) '
            . 'ON CONFLICT(key) DO UPDATE SET value = :value2',
        );
        $stmt->execute(['key' => $key, 'value' => $value, 'value2' => $value]);
    }

    /**
     * Increment a numeric metadata value.
     */
    public function incrementMeta(string $key, int $increment = 1): void
    {
        $current = (int) ($this->getMeta($key) ?? '0');
        $this->setMeta($key, (string) ($current + $increment));
    }

    /**
     * Retry a transaction closure on SQLITE_BUSY/LOCKED.
     *
     * @param Closure(): void $transaction
     */
    private function ingestWithRetry(Closure $transaction): void
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRY_ATTEMPTS; $attempt++) {
            try {
                $transaction();

                return;
            } catch (PDOException $e) {
                $isRetryable = self::isSqliteBusy($e);

                if (!$isRetryable || $attempt === self::MAX_RETRY_ATTEMPTS) {
                    if ($isRetryable) {
                        $this->metricRegistry?->counter('studio.store.busy')->increment();

                        throw StudioException::storeBusy($attempt, $e);
                    }

                    throw $e;
                }

                $delay = (int) ((float) self::BASE_DELAY_MS * (self::DELAY_MULTIPLIER ** (float) ($attempt - 1)));
                $jitter = random_int(0, (int) ((float) $delay * 0.5));
                usleep(($delay + $jitter) * 1000);
            }
        }
    }

    /**
     * Check if a PDOException is a retryable SQLite busy/locked error.
     */
    private static function isSqliteBusy(PDOException $e): bool
    {
        $errorCode = (is_array($e->errorInfo) && isset($e->errorInfo[1])) ? $e->errorInfo[1] : 0;
        $sqliteCode = is_int($errorCode) ? $errorCode : (int) (is_string($errorCode) ? $errorCode : 0);

        if ($sqliteCode === 5 || $sqliteCode === 6) {
            return true;
        }

        $message = mb_strtolower($e->getMessage());

        return str_contains($message, 'database is locked')
            || str_contains($message, 'database is busy');
    }
}
