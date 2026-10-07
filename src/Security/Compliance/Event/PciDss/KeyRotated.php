<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\PciDss;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records a cryptographic key rotation event.
 *
 * Supports controls for PCI-DSS Requirement 3 key management procedures.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class KeyRotated extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $operatorIdentity,
        public string $keyPurpose,
        public string $previousKeyId,
        public string $newKeyId,
        public string $rotationReason,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'pci_dss';
    }

    public function eventType(): string
    {
        return 'key_rotated';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'operator_identity' => $this->operatorIdentity,
            'key_purpose' => $this->keyPurpose,
            'previous_key_id' => $this->previousKeyId,
            'new_key_id' => $this->newKeyId,
            'rotation_reason' => $this->rotationReason,
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     operator_identity?: string,
     *     key_purpose?: string,
     *     previous_key_id?: string,
     *     new_key_id?: string,
     *     rotation_reason?: string,
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
            operatorIdentity: $data['operator_identity'] ?? '',
            keyPurpose: $data['key_purpose'] ?? '',
            previousKeyId: $data['previous_key_id'] ?? '',
            newKeyId: $data['new_key_id'] ?? '',
            rotationReason: $data['rotation_reason'] ?? '',
        );
    }
}
