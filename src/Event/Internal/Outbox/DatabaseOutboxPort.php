<?php

declare(strict_types=1);

namespace Pulsar\Event\Internal\Outbox;

use DateTimeImmutable;
use JsonException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Row;
use Pulsar\Event\Contract\OutboxPort;
use Pulsar\Event\EventEnvelope;
use SodiumException;

use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Database-backed transactional-outbox adapter for {@see OutboxPort}.
 *
 * F387.7 / ADR-0027: the saga and workflow modules depend on a
 * transactional outbox so domain writes and integration-event
 * publication share the same DB transaction. Without an
 * implementation of {@see OutboxPort} the dual-write problem
 * resurfaces: an event published before commit can fire on a
 * rolled-back transaction; one published after commit can be lost
 * if the publish itself fails. This adapter persists the envelope
 * inside the caller's transaction and a separate relay drains the
 * `outbox_events` table after commit (see
 * {@see OutboxRelay}).
 *
 * Schema is created on `installSchema()` — keep the DDL here so
 * downstream projects pick the table up via a single migration.
 */
#[Internal]
final readonly class DatabaseOutboxPort implements OutboxPort
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    private const string TABLE = 'outbox_events';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * Idempotent DDL: create the outbox table and its publish-pending index.
     *
     * Called from a migration or boot wiring. Multiple invocations are
     * safe — the `IF NOT EXISTS` clause covers re-runs.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function installSchema(): void
    {
        $driver = $this->connection->driver();

        match ($driver) {
            Driver::SQLite => $this->installSqliteSchema(),
            Driver::MySQL => $this->installMysqlSchema(),
            Driver::PostgreSQL => $this->installPostgresSchema(),
        };
    }

    /**
     * @throws JsonException
     * @throws SodiumException
     */
    #[Override]
    public function store(EventEnvelope $envelope): void
    {
        $this->insert($envelope);
    }

    /**
     * @param list<EventEnvelope> $envelopes
     *
     * @throws JsonException
     * @throws SodiumException
     */
    #[Override]
    public function storeBatch(array $envelopes): void
    {
        foreach ($envelopes as $envelope) {
            $this->insert($envelope);
        }
    }

    #[Override]
    public function markPublished(string $eventId): void
    {
        $this->connection->execute(
            sprintf(
                'UPDATE %s SET published_at = :published_at, last_error = NULL WHERE event_id = :event_id',
                self::TABLE,
            ),
            [
                'published_at' => new DateTimeImmutable()->format('Y-m-d H:i:s.u'),
                'event_id' => $eventId,
            ],
        );
    }

    /**
     * Record a publish failure so the relay can back off and an
     * operator can see the last error without trawling logs.
     */
    public function recordFailure(string $eventId, string $error): void
    {
        $this->connection->execute(
            sprintf(
                'UPDATE %s SET publish_attempts = publish_attempts + 1, last_error = :err WHERE event_id = :event_id',
                self::TABLE,
            ),
            [
                'err' => $error,
                'event_id' => $eventId,
            ],
        );
    }

    /**
     * @return list<EventEnvelope>
     *
     * @throws JsonException
     * @throws SodiumException
     */
    #[Override]
    public function pendingEvents(int $limit = 100): array
    {
        return $this->connection->query(
            sprintf(
                'SELECT event_id, event_type, schema_version, payload_json, payload_hash, '
                . 'metadata_json, origin_module, scope FROM %s '
                . 'WHERE published_at IS NULL ORDER BY created_at ASC LIMIT :limit',
                self::TABLE,
            ),
            ['limit' => $limit],
        )->map(fn(Row $row): EventEnvelope => $this->hydrate($row->data));
    }

    /**
     * @throws JsonException
     * @throws SodiumException
     */
    private function insert(EventEnvelope $envelope): void
    {
        $now = new DateTimeImmutable()->format('Y-m-d H:i:s.u');

        $this->connection->execute(
            sprintf(
                'INSERT INTO %s (event_id, event_type, schema_version, payload_json, '
                . 'payload_hash, metadata_json, origin_module, scope, '
                . 'publish_attempts, last_error, published_at, created_at) '
                . 'VALUES (:event_id, :event_type, :schema_version, :payload_json, '
                . ':payload_hash, :metadata_json, :origin_module, :scope, '
                . '0, NULL, NULL, :created_at)',
                self::TABLE,
            ),
            [
                'event_id' => $envelope->eventId,
                'event_type' => $envelope->eventType,
                'schema_version' => $envelope->schemaVersion,
                'payload_json' => json_encode(
                    $envelope->payload,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
                'payload_hash' => $envelope->payloadHash,
                'metadata_json' => json_encode(
                    $envelope->metadata->toArray(),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
                'origin_module' => $envelope->originModule,
                'scope' => $envelope->scope->value,
                'created_at' => $now,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws JsonException
     * @throws SodiumException
     */
    private function hydrate(array $row): EventEnvelope
    {
        /** @var mixed $rawPayloadJson */
        $rawPayloadJson = $row['payload_json'] ?? null;
        /** @var mixed $rawMetadataJson */
        $rawMetadataJson = $row['metadata_json'] ?? null;
        /** @var mixed $rawEventType */
        $rawEventType = $row['event_type'] ?? null;
        /** @var mixed $rawSchemaVersion */
        $rawSchemaVersion = $row['schema_version'] ?? null;
        /** @var mixed $rawOriginModule */
        $rawOriginModule = $row['origin_module'] ?? null;
        /** @var mixed $rawScope */
        $rawScope = $row['scope'] ?? null;
        /** @var mixed $rawEventId */
        $rawEventId = $row['event_id'] ?? null;

        $payloadJson = is_string($rawPayloadJson) ? $rawPayloadJson : '';
        $metadataJson = is_string($rawMetadataJson) ? $rawMetadataJson : '';
        $eventType = is_string($rawEventType) ? $rawEventType : '';
        $schemaVersion = is_int($rawSchemaVersion) ? $rawSchemaVersion : 0;
        $originModule = is_string($rawOriginModule) ? $rawOriginModule : null;
        $scope = is_string($rawScope) ? $rawScope : null;
        $eventId = is_string($rawEventId) ? $rawEventId : '';

        /** @var mixed $payload */
        $payload = $payloadJson !== ''
            ? json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR)
            : [];
        /** @var mixed $metadata */
        $metadata = $metadataJson !== ''
            ? json_decode($metadataJson, true, 512, JSON_THROW_ON_ERROR)
            : [];

        if (!is_array($payload)) {
            $payload = [];
        }
        if (!is_array($metadata)) {
            $metadata = [];
        }

        /** @var array<string, mixed> $payloadTyped */
        $payloadTyped = $payload;
        /** @var array<string, mixed> $metadataTyped */
        $metadataTyped = $metadata;

        return EventEnvelope::fromArray([
            'event_id' => $eventId,
            'event_type' => $eventType,
            'schema_version' => $schemaVersion,
            'metadata' => $metadataTyped,
            'payload' => $payloadTyped,
            'origin_module' => $originModule,
            'scope' => $scope,
        ]);
    }

    private function installSqliteSchema(): void
    {
        $this->connection->execute(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                event_id TEXT PRIMARY KEY,
                event_type TEXT NOT NULL,
                schema_version INTEGER NOT NULL,
                payload_json TEXT NOT NULL,
                payload_hash TEXT NOT NULL,
                metadata_json TEXT NOT NULL,
                origin_module TEXT,
                scope TEXT NOT NULL,
                publish_attempts INTEGER NOT NULL DEFAULT 0,
                last_error TEXT,
                published_at TEXT,
                created_at TEXT NOT NULL
            )',
            self::TABLE,
        ));
        $this->connection->execute(sprintf(
            'CREATE INDEX IF NOT EXISTS %s_pending_idx ON %s (published_at, created_at)',
            self::TABLE,
            self::TABLE,
        ));
    }

    private function installMysqlSchema(): void
    {
        $this->connection->execute(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                event_id VARCHAR(64) NOT NULL PRIMARY KEY,
                event_type VARCHAR(255) NOT NULL,
                schema_version INT NOT NULL,
                payload_json LONGTEXT NOT NULL,
                payload_hash VARCHAR(128) NOT NULL,
                metadata_json LONGTEXT NOT NULL,
                origin_module VARCHAR(255) NULL,
                scope VARCHAR(64) NOT NULL,
                publish_attempts INT NOT NULL DEFAULT 0,
                last_error TEXT NULL,
                published_at DATETIME(6) NULL,
                created_at DATETIME(6) NOT NULL,
                INDEX %s_pending_idx (published_at, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin',
            self::TABLE,
            self::TABLE,
        ));
    }

    private function installPostgresSchema(): void
    {
        $this->connection->execute(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                event_id TEXT PRIMARY KEY,
                event_type TEXT NOT NULL,
                schema_version INTEGER NOT NULL,
                payload_json TEXT NOT NULL,
                payload_hash TEXT NOT NULL,
                metadata_json TEXT NOT NULL,
                origin_module TEXT,
                scope TEXT NOT NULL,
                publish_attempts INTEGER NOT NULL DEFAULT 0,
                last_error TEXT,
                published_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL
            )',
            self::TABLE,
        ));
        $this->connection->execute(sprintf(
            'CREATE INDEX IF NOT EXISTS %s_pending_idx ON %s (published_at, created_at) WHERE published_at IS NULL',
            self::TABLE,
            self::TABLE,
        ));
    }
}
