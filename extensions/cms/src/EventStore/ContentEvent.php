<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\EventStore;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Append-only event log entry for content mutations.
 *
 * Part of the optional event-sourced mode (enabled via CmsConfig.eventSourcing).
 * Events are immutable and never deleted or modified, providing complete
 * causal history of who changed what, when, and why.
 *
 * @psalm-api Public DTO returned from ContentEventStoreInterface; consumed
 *            by audit views and event-replay projections.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ContentEvent
{
    /**
     * @param string $id UUIDv7
     * @param string $contentId UUIDv7 aggregate ID
     * @param int $sequence Monotonically increasing per content_id
     * @param string $eventType Event type (e.g., ContentCreated, TranslationUpdated, Published)
     * @param array<string, mixed> $payload Full event data (what changed)
     * @param string $actorId UUIDv7 user who performed the action
     * @param string|null $reason Optional reason for the change
     * @param string $evidenceHash BLAKE2b hash of (content_id + sequence + event_type + payload)
     * @param DateTimeImmutable $createdAt When the event occurred
     */
    public function __construct(
        public string $id,
        public string $contentId,
        public int $sequence,
        public string $eventType,
        public array $payload,
        public string $actorId,
        public ?string $reason,
        public string $evidenceHash,
        public DateTimeImmutable $createdAt,
    ) {}
}
