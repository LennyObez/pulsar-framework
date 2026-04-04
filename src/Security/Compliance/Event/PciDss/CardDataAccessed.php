<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\PciDss;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

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
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     accessor_identity?: string,
     *     data_type?: string,
     *     purpose?: string,
     *     masked_identifier?: string,
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
            accessorIdentity: $data['accessor_identity'] ?? '',
            dataType: $data['data_type'] ?? '',
            purpose: $data['purpose'] ?? '',
            maskedIdentifier: $data['masked_identifier'] ?? '',
        );
    }
}
