<?php

declare(strict_types=1);

namespace Pulsar\Security\JustifiedAccess\Event;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_string;

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
            justificationId: is_string($data['justification_id'] ?? null) ? $data['justification_id'] : '',
            actorId: is_string($data['actor_id'] ?? null) ? $data['actor_id'] : '',
            resourceType: is_string($data['resource_type'] ?? null) ? $data['resource_type'] : '',
            resourceId: is_string($data['resource_id'] ?? null) ? $data['resource_id'] : '',
            category: is_string($data['category'] ?? null) ? $data['category'] : '',
            dataClassification: is_string($data['data_classification'] ?? null) ? $data['data_classification'] : '',
        );
    }
}
