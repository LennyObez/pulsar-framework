<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Sox;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_array;
use function is_string;

/**
 * Records modification of financial data requiring SOX audit trail.
 *
 * Supports controls for SOX Section 302 internal controls over financial reporting.
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $fieldSnapshots */
        $fieldSnapshots = is_array($data['field_snapshots'] ?? null) ? $data['field_snapshots'] : [];

        return new self(
            eventId: is_string($data['event_id'] ?? null) ? $data['event_id'] : '',
            occurredAt: is_string($data['occurred_at'] ?? null) ? new DateTimeImmutable($data['occurred_at']) : new DateTimeImmutable(),
            correlationId: is_string($data['correlation_id'] ?? null) ? $data['correlation_id'] : '',
            nonce: is_string($data['nonce'] ?? null) ? $data['nonce'] : '',
            modifierIdentity: is_string($data['modifier_identity'] ?? null) ? $data['modifier_identity'] : '',
            entityType: is_string($data['entity_type'] ?? null) ? $data['entity_type'] : '',
            entityId: is_string($data['entity_id'] ?? null) ? $data['entity_id'] : '',
            fieldSnapshots: $fieldSnapshots,
            reason: is_string($data['reason'] ?? null) ? $data['reason'] : '',
        );
    }
}
