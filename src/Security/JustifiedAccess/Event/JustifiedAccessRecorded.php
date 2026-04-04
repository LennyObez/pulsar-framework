<?php

declare(strict_types=1);

namespace Pulsar\Security\JustifiedAccess\Event;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Emitted when a justified access event is recorded.
 *
 * Supports controls for PCI-DSS Req 7.1, HIPAA §164.312(b),
 * GDPR Art. 5(1)(b), and SOC 2 CC6.1.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class JustifiedAccessRecorded extends ComplianceEvent
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
        public string $category,
        public string $dataClassification,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'multi';
    }

    public function eventType(): string
    {
        return 'justified_access_recorded';
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
            'category' => $this->category,
            'data_classification' => $this->dataClassification,
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
     *     category?: string,
     *     data_classification?: string,
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
            category: $data['category'] ?? '',
            dataClassification: $data['data_classification'] ?? '',
        );
    }
}
