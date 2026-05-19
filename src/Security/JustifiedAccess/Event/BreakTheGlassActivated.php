<?php

declare(strict_types=1);

namespace Pulsar\Security\JustifiedAccess\Event;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Emitted when a break-the-glass emergency access is activated.
 *
 * This is a high-severity compliance event that triggers mandatory
 * post-incident review per HIPAA, PCI-DSS, and SOC 2 requirements.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class BreakTheGlassActivated extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $justificationId,
        public string $actorId,
        public string $resourceType,
        public string $resourceId,
        public string $justificationText,
        public int $durationSeconds,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'multi';
    }

    public function eventType(): string
    {
        return 'break_the_glass_activated';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'justification_id' => $this->justificationId,
            'actor_id' => $this->actorId,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'justification_text' => $this->justificationText,
            'duration_seconds' => $this->durationSeconds,
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     justification_id?: string,
     *     actor_id?: string,
     *     resource_type?: string,
     *     resource_id?: string,
     *     justification_text?: string,
     *     duration_seconds?: int,
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
            justificationId: $data['justification_id'] ?? '',
            actorId: $data['actor_id'] ?? '',
            resourceType: $data['resource_type'] ?? '',
            resourceId: $data['resource_id'] ?? '',
            justificationText: $data['justification_text'] ?? '',
            durationSeconds: $data['duration_seconds'] ?? 900,
        );
    }
}
