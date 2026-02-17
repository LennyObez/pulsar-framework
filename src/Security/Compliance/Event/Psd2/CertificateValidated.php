<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Psd2;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_string;

/**
 * Records validation of a PSD2 eIDAS certificate.
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class CertificateValidated extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $serialNumber,
        public string $certificateType,
        public string $authorizationNumber,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'psd2';
    }

    public function eventType(): string
    {
        return 'certificate_validated';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'serial_number' => $this->serialNumber,
            'certificate_type' => $this->certificateType,
            'authorization_number' => $this->authorizationNumber,
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
            serialNumber: is_string($data['serial_number'] ?? null) ? $data['serial_number'] : '',
            certificateType: is_string($data['certificate_type'] ?? null) ? $data['certificate_type'] : '',
            authorizationNumber: is_string($data['authorization_number'] ?? null) ? $data['authorization_number'] : '',
        );
    }
}
