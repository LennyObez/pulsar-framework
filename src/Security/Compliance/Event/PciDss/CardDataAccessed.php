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
 * Records access to cardholder data.
 *
 * Supports controls for PCI-DSS Requirement 10 tracking access to cardholder data.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class CardDataAccessed extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $accessorIdentity,
        public string $dataType,
        public string $purpose,
        public string $maskedIdentifier,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'pci_dss';
    }

    public function eventType(): string
    {
        return 'card_data_accessed';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'accessor_identity' => $this->accessorIdentity,
            'data_type' => $this->dataType,
            'purpose' => $this->purpose,
            'masked_identifier' => $this->maskedIdentifier,
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
            accessorIdentity: is_string($data['accessor_identity'] ?? null) ? $data['accessor_identity'] : '',
            dataType: is_string($data['data_type'] ?? null) ? $data['data_type'] : '',
            purpose: is_string($data['purpose'] ?? null) ? $data['purpose'] : '',
            maskedIdentifier: is_string($data['masked_identifier'] ?? null) ? $data['masked_identifier'] : '',
        );
    }
}
