<?php

declare(strict_types=1);

namespace Pulsar\Security\JustifiedAccess\Event;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Emitted when an unusual access pattern is detected for an actor.
 *
 * Supports controls for PSD2 Art. 73, DORA Art. 9, and SOC 2 CC6.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class UnusualAccessPatternDetected extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $actorId,
        public int $uniqueResourcesAccessed,
        public int $threshold,
        public int $windowSeconds,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'multi';
    }

    public function eventType(): string
    {
        return 'unusual_access_pattern_detected';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'actor_id' => $this->actorId,
            'unique_resources_accessed' => $this->uniqueResourcesAccessed,
            'threshold' => $this->threshold,
            'window_seconds' => $this->windowSeconds,
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     actor_id?: string,
     *     unique_resources_accessed?: int,
     *     threshold?: int,
     *     window_seconds?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $occurredAt = $data['occurred_at'] ?? null;

        return new self(
            eventId: $data['event_id'] ?? '',
            occurredAt: $occurredAt !== null ? new DateTimeImmutable($occurredAt) : new DateTimeImmutable(),
            correlationId: $data['correlation_id'] ?? '',
            nonce: $data['nonce'] ?? '',
            actorId: $data['actor_id'] ?? '',
            uniqueResourcesAccessed: $data['unique_resources_accessed'] ?? 0,
            threshold: $data['threshold'] ?? 50,
            windowSeconds: $data['window_seconds'] ?? 3600,
        );
    }
}
