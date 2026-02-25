<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\EventStore\ContentEvent;
use Pulsar\Extension\Cms\EventStore\ContentEventStoreInterface;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[Internal(reason: 'Raw-DB repository — use ContentEventStoreInterface for public API')]
final readonly class DbContentEventRepository implements ContentEventStoreInterface
{
    private const string SQL_APPEND = <<<'SQL'
        INSERT INTO cms_content_events (
            id, content_id, sequence, event_type, payload,
            actor_id, reason, evidence_hash, created_at
        ) VALUES (
            :id, :content_id, :sequence, :event_type, :payload,
            :actor_id, :reason, :evidence_hash, :created_at
        )
        SQL;

    private const string SQL_GET_EVENTS = <<<'SQL'
        SELECT * FROM cms_content_events
        WHERE content_id = :content_id
        ORDER BY sequence ASC
        SQL;

    private const string SQL_GET_EVENTS_AFTER = <<<'SQL'
        SELECT * FROM cms_content_events
        WHERE content_id = :content_id AND sequence > :after_sequence
        ORDER BY sequence ASC
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function append(ContentEvent $event): void
    {
        $this->connection->execute(self::SQL_APPEND, [
            'id' => $event->id,
            'content_id' => $event->contentId,
            'sequence' => $event->sequence,
            'event_type' => $event->eventType,
            'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR),
            'actor_id' => $event->actorId,
            'reason' => $event->reason,
            'evidence_hash' => $event->evidenceHash,
            'created_at' => $event->createdAt->format('c'),
        ]);
    }

    public function getEvents(string $contentId, ?int $afterSequence = null): array
    {
        if ($afterSequence !== null) {
            $result = $this->connection->query(self::SQL_GET_EVENTS_AFTER, [
                'content_id' => $contentId,
                'after_sequence' => $afterSequence,
            ]);
        } else {
            $result = $this->connection->query(self::SQL_GET_EVENTS, [
                'content_id' => $contentId,
            ]);
        }

        return $result->map(self::hydrate(...));
    }

    public function rebuildProjection(string $contentId): void
    {
        // Projection rebuild requires access to content/translation repositories.
        // Orchestrated by the domain service layer; repository provides event data only.
    }

    private static function hydrate(Row $row): ContentEvent
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($row->getString('payload'), true, 512, JSON_THROW_ON_ERROR);

        return new ContentEvent(
            id: $row->getString('id'),
            contentId: $row->getString('content_id'),
            sequence: $row->getInt('sequence'),
            eventType: $row->getString('event_type'),
            payload: $payload,
            actorId: $row->getString('actor_id'),
            reason: $row->getNullableString('reason'),
            evidenceHash: $row->getString('evidence_hash'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }
}
