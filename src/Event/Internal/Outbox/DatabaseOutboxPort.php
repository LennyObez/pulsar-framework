<?php

declare(strict_types=1);

namespace Pulsar\Event\Internal\Outbox;

use DateTimeImmutable;
use JsonException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
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
use const PHP_INT_MAX;

/**
 * Database-backed transactional-outbox adapter for {@see OutboxPort}.
 *
 * ADR-0027: the saga and workflow modules depend on a
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
 * `outbox_events` is created by
 * `src/Event/Database/Migration/20260821000004_create_event_outbox_table.php` and by
 * nothing else. This class stores and drains envelopes; it does not build the table it
 * stores them in, so the outbox no longer requires CREATE on the role that serves
 * requests (ADR-0043).
 */
#[Internal]
final readonly class DatabaseOutboxPort implements OutboxPort
{
    private const string TABLE = 'outbox_events';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

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
     * Record a publish failure so the relay can bound retries and an
     * operator can see the last error without trawling logs.
     *
     * When the incremented attempt count reaches $maxAttempts the envelope is
     * dead-lettered (stamped with dead_lettered_at), which removes it from
     * {@see pendingEvents()} / {@see pendingForRelay()} so a permanently-failing
     * "poison" envelope can no longer starve the FIFO-ordered healthy events
     * behind it. The default of PHP_INT_MAX preserves the prior "retry forever"
     * behaviour for any caller that does not opt into a cap. The
     * `dead_lettered_at IS NULL` guard makes the update idempotent and the
     * `ELSE dead_lettered_at` keeps an already-set timestamp intact.
     */
    #[Override]
    public function recordFailure(string $eventId, string $error, int $maxAttempts = PHP_INT_MAX): void
    {
        $this->connection->execute(
            sprintf(
                'UPDATE %s SET publish_attempts = publish_attempts + 1, last_error = :err, '
                . 'dead_lettered_at = CASE WHEN publish_attempts + 1 >= :max THEN :now ELSE dead_lettered_at END '
                . 'WHERE event_id = :event_id AND dead_lettered_at IS NULL',
                self::TABLE,
            ),
            [
                'err' => $error,
                'max' => $maxAttempts,
                'now' => new DateTimeImmutable()->format('Y-m-d H:i:s.u'),
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
                . 'WHERE published_at IS NULL AND dead_lettered_at IS NULL '
                . 'ORDER BY created_at ASC LIMIT :limit',
                self::TABLE,
            ),
            ['limit' => $limit],
        )->map(fn(Row $row): EventEnvelope => $this->hydrate($row->data));
    }

    /**
     * Pending events for the relay, bounded by the publish-attempt cap, each
     * paired with its current attempt count so the relay can tell — without an
     * extra query — when a failure pushes an envelope over the cap.
     *
     * @return list<PendingEnvelope>
     *
     * @throws JsonException
     * @throws SodiumException
     */
    public function pendingForRelay(int $limit, int $maxAttempts): array
    {
        return $this->connection->query(
            sprintf(
                'SELECT event_id, event_type, schema_version, payload_json, payload_hash, '
                . 'metadata_json, origin_module, scope, publish_attempts FROM %s '
                . 'WHERE published_at IS NULL AND dead_lettered_at IS NULL AND publish_attempts < :max '
                . 'ORDER BY created_at ASC LIMIT :limit',
                self::TABLE,
            ),
            ['limit' => $limit, 'max' => $maxAttempts],
        )->map(fn(Row $row): PendingEnvelope => new PendingEnvelope(
            envelope: $this->hydrate($row->data),
            publishAttempts: $row->getInt('publish_attempts'),
        ));
    }

    /**
     * @return list<EventEnvelope>
     *
     * @throws JsonException
     * @throws SodiumException
     */
    #[Override]
    public function deadLetteredEvents(int $limit = 100): array
    {
        return $this->connection->query(
            sprintf(
                'SELECT event_id, event_type, schema_version, payload_json, payload_hash, '
                . 'metadata_json, origin_module, scope FROM %s '
                . 'WHERE dead_lettered_at IS NOT NULL ORDER BY dead_lettered_at ASC LIMIT :limit',
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
}
