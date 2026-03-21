<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\PciDss;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_string;

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
            operatorIdentity: is_string($data['operator_identity'] ?? null) ? $data['operator_identity'] : '',
            keyPurpose: is_string($data['key_purpose'] ?? null) ? $data['key_purpose'] : '',
            previousKeyId: is_string($data['previous_key_id'] ?? null) ? $data['previous_key_id'] : '',
            newKeyId: is_string($data['new_key_id'] ?? null) ? $data['new_key_id'] : '',
            rotationReason: is_string($data['rotation_reason'] ?? null) ? $data['rotation_reason'] : '',
        );
    }
}
