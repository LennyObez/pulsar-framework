<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Sox;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records modification of financial data requiring SOX audit trail.
 *
 * Supports controls for SOX Section 302 internal controls over financial reporting.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class FinancialDataModified extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param array<string, mixed> $fieldSnapshots
     */
    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $modifierIdentity,
        public string $entityType,
        public string $entityId,
        public array $fieldSnapshots,
        public string $reason,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'sox';
    }

    public function eventType(): string
    {
        return 'financial_data_modified';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'modifier_identity' => $this->modifierIdentity,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'field_snapshots' => $this->fieldSnapshots,
            'reason' => $this->reason,
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     modifier_identity?: string,
     *     entity_type?: string,
     *     entity_id?: string,
     *     field_snapshots?: array<string, mixed>,
     *     reason?: string,
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
            modifierIdentity: $data['modifier_identity'] ?? '',
            entityType: $data['entity_type'] ?? '',
            entityId: $data['entity_id'] ?? '',
            fieldSnapshots: $data['field_snapshots'] ?? [],
            reason: $data['reason'] ?? '',
        );
    }
}
