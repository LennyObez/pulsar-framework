<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\EventStore;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;

use function sprintf;

/**
 * Service for the append-only content event store.
 *
 * Manages event creation with monotonic sequence numbers and evidence hash computation.
 * Only active when CmsConfig.eventSourcing is enabled.
 */
#[Internal]
final readonly class ContentEventService
{
    public function __construct(
        private ConnectionInterface $db,
        private CmsConfig $config,
    ) {}

    /**
     * Whether the event store is active (CmsConfig.eventSourcing enabled).
     */
    public function isEnabled(): bool
    {
        return $this->config->eventSourcing;
    }

    /**
     * Append a new event to the content event store.
     *
     * @param array<string, mixed> $payload Full event data (what changed)
     */
    public function append(
        string $contentId,
        string $eventType,
        array $payload,
        string $actorId,
        ?string $reason = null,
    ): ContentEvent {
        if (!$this->isEnabled()) {
            return $this->createNoopEvent($contentId, $eventType, $payload, $actorId, $reason);
        }

        return $this->db->transaction(function (ConnectionInterface $db) use ($contentId, $eventType, $payload, $actorId, $reason): ContentEvent {
            // Get the next sequence number atomically
            $result = $db->query(
                'SELECT COALESCE(MAX(sequence), 0) AS max_seq FROM cms_content_events WHERE content_id = :content_id',
                ['content_id' => $contentId],
            );

            $nextSequence = ((int) $result->firstOrFail()->get('max_seq')) + 1;

            $evidenceHash = self::computeEvidenceHash($contentId, $nextSequence, $eventType, $payload);
            $now = new DateTimeImmutable();
            $id = $this->generateUuidV7();
            $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

            $event = new ContentEvent(
                id: $id,
                contentId: $contentId,
                sequence: $nextSequence,
                eventType: $eventType,
                payload: $payload,
                actorId: $actorId,
                reason: $reason,
                evidenceHash: $evidenceHash,
                createdAt: $now,
            );

            $db->execute(
                <<<'SQL'
                    INSERT INTO cms_content_events (id, content_id, sequence, event_type, payload, actor_id, reason, evidence_hash, created_at)
                    VALUES (:id, :content_id, :sequence, :event_type, :payload, :actor_id, :reason, :evidence_hash, :created_at)
                    SQL,
                [
                    'id' => $event->id,
                    'content_id' => $event->contentId,
                    'sequence' => $event->sequence,
                    'event_type' => $event->eventType,
                    'payload' => $payloadJson,
                    'actor_id' => $event->actorId,
                    'reason' => $event->reason,
                    'evidence_hash' => $event->evidenceHash,
                    'created_at' => $event->createdAt->format('Y-m-d H:i:s'),
                ],
            );

            return $event;
        });
    }

    /**
     * Retrieve events for a content item, optionally starting after a given sequence.
     *
     * @return list<ContentEvent>
     */
    public function getEvents(string $contentId, ?int $afterSequence = null): array
    {
        $sql = 'SELECT id, content_id, sequence, event_type, payload, actor_id, reason, evidence_hash, created_at FROM cms_content_events WHERE content_id = :content_id';
        $bindings = ['content_id' => $contentId];

        if ($afterSequence !== null) {
            $sql .= ' AND sequence > :after_sequence';
            $bindings['after_sequence'] = $afterSequence;
        }

        $sql .= ' ORDER BY sequence ASC';

        $result = $this->db->query($sql, $bindings);
        $events = [];

        foreach ($result->rows as $row) {
            $events[] = new ContentEvent(
                id: (string) $row->get('id'),
                contentId: (string) $row->get('content_id'),
                sequence: (int) $row->get('sequence'),
                eventType: (string) $row->get('event_type'),
                payload: json_decode((string) $row->get('payload'), true, 512, JSON_THROW_ON_ERROR),
                actorId: (string) $row->get('actor_id'),
                reason: $row->get('reason') !== null ? (string) $row->get('reason') : null,
                evidenceHash: (string) $row->get('evidence_hash'),
                createdAt: new DateTimeImmutable((string) $row->get('created_at')),
            );
        }

        return $events;
    }

    /**
     * Compute the BLAKE2b evidence hash for an event.
     *
     * @param array<string, mixed> $payload
     */
    public static function computeEvidenceHash(
        string $contentId,
        int $sequence,
        string $eventType,
        array $payload,
    ): string {
        $data = implode("\0", [
            $contentId,
            (string) $sequence,
            $eventType,
            json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        return hash('blake2b', $data);
    }

    /**
     * Create a no-op event when the event store is disabled.
     *
     * @param array<string, mixed> $payload
     */
    private function createNoopEvent(
        string $contentId,
        string $eventType,
        array $payload,
        string $actorId,
        ?string $reason,
    ): ContentEvent {
        return new ContentEvent(
            id: $this->generateUuidV7(),
            contentId: $contentId,
            sequence: 0,
            eventType: $eventType,
            payload: $payload,
            actorId: $actorId,
            reason: $reason,
            evidenceHash: self::computeEvidenceHash($contentId, 0, $eventType, $payload),
            createdAt: new DateTimeImmutable(),
        );
    }

    private function generateUuidV7(): string
    {
        $time = (int) (microtime(true) * 1000);
        $hex = str_pad(dechex($time), 12, '0', STR_PAD_LEFT);
        $random = bin2hex(random_bytes(8));

        return sprintf(
            '%s-%s-7%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($random, 0, 3),
            dechex(0x80 | (hexdec(substr($random, 3, 2)) & 0x3F)) . substr($random, 5, 2),
            substr($random, 7, 12),
        );
    }
}
