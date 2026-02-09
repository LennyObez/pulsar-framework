<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Storage;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Exception\StudioException;
use Pulsar\Security\Crypto\HmacInterface;

use function array_fill;
use function count;
use function hash;
use function implode;
use function is_array;
use function sprintf;

/**
 * Database-backed event store using Pulsar's ConnectionInterface.
 *
 * Supports PostgreSQL, MySQL, and SQLite via the framework's database
 * abstraction. Uses SELECT ... FOR UPDATE for chain linearization on
 * PostgreSQL/MySQL, and BEGIN IMMEDIATE for SQLite fallback.
 */
#[Internal]
final class DatabaseEventStore implements EventStoreInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $tableName = 'studio_events',
        private readonly string $chainTableName = 'studio_chain',
        private readonly ?HmacInterface $hmac = null,
    ) {}

    #[Override]
    public function store(EventEnvelope $envelope, string $payloadJson, ?string $tenantHash = null): void
    {
        $this->connection->execute(
            sprintf(
                'INSERT INTO %s (
                    event_id, event_type, schema_version, timestamp_us,
                    request_id, trace_id, span_id, job_id,
                    app_env, hostname, tenant_hash,
                    payload_json, payload_hash, ciphertext_hash
                ) VALUES (
                    :event_id, :event_type, :schema_version, :timestamp_us,
                    :request_id, :trace_id, :span_id, :job_id,
                    :app_env, :hostname, :tenant_hash,
                    :payload_json, :payload_hash, :ciphertext_hash
                )',
                $this->tableName,
            ),
            [
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
            ],
        );
    }

    /**
     * Store event with chain link in an atomic transaction.
     *
     * Uses SELECT ... FOR UPDATE on PostgreSQL/MySQL for chain linearization.
     * Falls back to SQLite's implicit serialized writes within a transaction.
     */
    public function storeWithChain(
        EventEnvelope $envelope,
        string $payloadJson,
        ?string $tenantHash,
        ?string $chainMacKey,
    ): void {
        $this->connection->transaction(function (ConnectionInterface $conn) use (
            $envelope,
            $payloadJson,
            $tenantHash,
            $chainMacKey,
        ): void {
            // Store the event
            $this->store($envelope, $payloadJson, $tenantHash);

            // Read current chain tip with appropriate locking
            $previousHash = $this->readChainTip($conn);

            // Compute chain hash
            $currentHash = hash('sha256', $previousHash . '|' . $envelope->canonical());

            // Compute optional per-link MAC
            $linkMac = null;
            if ($chainMacKey !== null) {
                $linkMac = $this->hmac?->computeHex($currentHash, $chainMacKey);
            }

            // Insert chain link
            $conn->execute(
                sprintf(
                    'INSERT INTO %s (event_id, previous_hash, current_hash, link_mac)
                     VALUES (:event_id, :previous_hash, :current_hash, :link_mac)',
                    $this->chainTableName,
                ),
                [
                    'event_id' => $envelope->eventId,
                    'previous_hash' => $previousHash,
                    'current_hash' => $currentHash,
                    'link_mac' => $linkMac,
                ],
            );
        });
    }

    #[Override]
    public function query(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$whereClause, $bindings] = $this->buildWhereClause($filters, forQuery: true);

        $sql = sprintf(
            'SELECT * FROM %s %s ORDER BY timestamp_us DESC LIMIT :limit OFFSET :offset',
            $this->tableName,
            $whereClause,
        );

        $bindings['limit'] = $limit;
        $bindings['offset'] = $offset;

        $result = $this->connection->query($sql, $bindings);

        return $result->map(static fn($row) => $row->toArray());
    }

    #[Override]
    public function count(array $filters = []): int
    {
        [$whereClause, $bindings] = $this->buildWhereClause($filters, forQuery: false);

        $sql = sprintf('SELECT COUNT(*) as cnt FROM %s %s', $this->tableName, $whereClause);

        $result = $this->connection->query($sql, $bindings);
        $row = $result->first();

        return $row !== null ? $row->getInt('cnt') : 0;
    }

    #[Override]
    public function find(string $eventId): ?array
    {
        $result = $this->connection->query(
            sprintf('SELECT * FROM %s WHERE event_id = :event_id', $this->tableName),
            ['event_id' => $eventId],
        );

        $row = $result->first();

        return $row?->toArray();
    }

    #[Override]
    public function sizeInBytes(): int
    {
        if ($this->connection->driver() === Driver::SQLite) {
            $result = $this->connection->query(
                'SELECT page_count * page_size AS size FROM pragma_page_count(), pragma_page_size()',
            );
            $row = $result->first();

            return $row !== null ? $row->getInt('size') : 0;
        }

        // For MySQL/PostgreSQL, estimate based on table size
        if ($this->connection->driver() === Driver::PostgreSQL) {
            $result = $this->connection->query(
                'SELECT pg_total_relation_size(:table) AS size',
                ['table' => $this->tableName],
            );
            $row = $result->first();

            return $row !== null ? $row->getInt('size') : 0;
        }

        // MySQL
        $result = $this->connection->query(
            'SELECT (data_length + index_length) AS size FROM information_schema.TABLES WHERE table_name = :table',
            ['table' => $this->tableName],
        );
        $row = $result->first();

        return $row !== null ? $row->getInt('size') : 0;
    }

    #[Override]
    public function deleteOlderThan(int $timestampUs): int
    {
        return $this->connection->execute(
            sprintf('DELETE FROM %s WHERE timestamp_us < :timestamp_us', $this->tableName),
            ['timestamp_us' => $timestampUs],
        );
    }

    #[Override]
    public function deleteByEventTypes(array $eventTypes): int
    {
        if ($eventTypes === []) {
            return 0;
        }

        $placeholders = [];
        $bindings = [];
        foreach ($eventTypes as $i => $type) {
            $key = 'type_' . $i;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $type;
        }

        return $this->connection->execute(
            sprintf(
                'DELETE FROM %s WHERE event_type IN (%s)',
                $this->tableName,
                implode(', ', $placeholders),
            ),
            $bindings,
        );
    }

    #[Override]
    public function deleteByPayloadKey(string $eventType, string $jsonPath, string $value): int
    {
        $jsonExtract = match ($this->connection->driver()) {
            Driver::SQLite => sprintf('json_extract(payload_json, :path)'),
            Driver::PostgreSQL => sprintf("payload_json::jsonb #>> :path"),
            Driver::MySQL => sprintf('JSON_UNQUOTE(JSON_EXTRACT(payload_json, :path))'),
        };

        return $this->connection->execute(
            sprintf(
                'DELETE FROM %s WHERE event_type = :type AND %s = :value',
                $this->tableName,
                $jsonExtract,
            ),
            ['type' => $eventType, 'path' => $jsonPath, 'value' => $value],
        );
    }

    #[Override]
    public function clear(): void
    {
        $this->connection->execute(sprintf('DELETE FROM %s', $this->tableName));
        $this->connection->execute(sprintf('DELETE FROM %s', $this->chainTableName));
    }

    #[Override]
    public function vacuum(): void
    {
        if ($this->connection->driver() === Driver::SQLite) {
            $this->connection->execute('VACUUM');
        } elseif ($this->connection->driver() === Driver::PostgreSQL) {
            $this->connection->execute(sprintf('VACUUM %s', $this->tableName));
        } else {
            $this->connection->execute(sprintf('OPTIMIZE TABLE %s', $this->tableName));
        }
    }

    /**
     * Get chain links for verification.
     *
     * @return list<array<string, mixed>>
     */
    public function chainLinks(int $limit = 0, int $offset = 0): array
    {
        $sql = sprintf(
            'SELECT c.*, e.event_id as evt_id, e.event_type, e.schema_version, e.timestamp_us, e.trace_id, e.payload_hash '
            . 'FROM %s c '
            . 'JOIN %s e ON c.event_id = e.event_id '
            . 'ORDER BY c.id ASC',
            $this->chainTableName,
            $this->tableName,
        );

        if ($limit > 0) {
            $sql .= sprintf(' LIMIT %d OFFSET %d', $limit, $offset);
        }

        $result = $this->connection->query($sql);

        return $result->map(static fn($row) => $row->toArray());
    }

    /**
     * Read the current chain tip hash with appropriate locking.
     */
    private function readChainTip(ConnectionInterface $conn): string
    {
        $lockSuffix = match ($conn->driver()) {
            Driver::PostgreSQL, Driver::MySQL => ' FOR UPDATE',
            Driver::SQLite => '',
        };

        $result = $conn->query(
            sprintf(
                'SELECT current_hash FROM %s ORDER BY id DESC LIMIT 1%s',
                $this->chainTableName,
                $lockSuffix,
            ),
        );

        $row = $result->first();

        return $row !== null
            ? $row->getString('current_hash')
            : hash('sha256', 'PULSAR_STUDIO_CHAIN_SEED');
    }

    /**
     * Build WHERE clause and bindings from filters.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhereClause(array $filters, bool $forQuery): array
    {
        $where = [];
        $bindings = [];

        if (isset($filters['event_type'])) {
            $eventTypeFilter = $filters['event_type'];
            if (is_array($eventTypeFilter)) {
                $placeholders = [];
                /** @var list<string> $eventTypeFilter */
                foreach ($eventTypeFilter as $i => $type) {
                    $key = 'event_type_' . $i;
                    $placeholders[] = ':' . $key;
                    $bindings[$key] = $type;
                }
                $where[] = 'event_type IN (' . implode(', ', $placeholders) . ')';
            } else {
                /** @var string $eventTypeFilter */
                $where[] = 'event_type = :event_type';
                $bindings['event_type'] = $eventTypeFilter;
            }
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

        if ($forQuery) {
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

            if (isset($filters['since_id'])) {
                $where[] = 'id > :since_id';
                /** @var int $sinceIdFilter */
                $sinceIdFilter = $filters['since_id'];
                $bindings['since_id'] = $sinceIdFilter;
            }
        }

        $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        return [$whereClause, $bindings];
    }
}
