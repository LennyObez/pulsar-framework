<?php

declare(strict_types=1);

namespace Pulsar\Security\JustifiedAccess\Event;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_int;
use function is_string;

/**
 * Emitted when an unusual access pattern is detected for an actor.
 *
 * Supports controls for PSD2 Art. 73, DORA Art. 9, and SOC 2 CC6.
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            eventId: is_string($data['event_id'] ?? null) ? $data['event_id'] : '',
            occurredAt: is_string($data['occurred_at'] ?? null) ? new DateTimeImmutable($data['occurred_at']) : new DateTimeImmutable(),
            correlationId: is_string($data['correlation_id'] ?? null) ? $data['correlation_id'] : '',
            nonce: is_string($data['nonce'] ?? null) ? $data['nonce'] : '',
            actorId: is_string($data['actor_id'] ?? null) ? $data['actor_id'] : '',
            uniqueResourcesAccessed: is_int($data['unique_resources_accessed'] ?? null) ? $data['unique_resources_accessed'] : 0,
            threshold: is_int($data['threshold'] ?? null) ? $data['threshold'] : 50,
            windowSeconds: is_int($data['window_seconds'] ?? null) ? $data['window_seconds'] : 3600,
        );
    }
}
