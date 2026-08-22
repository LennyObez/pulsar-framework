<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Hipaa;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records access to Protected Health Information (PHI).
 *
 * Supports controls for HIPAA Security Rule access logging requirements.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class PhiAccessed extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param list<string> $phiCategories
     */
    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $accessorIdentity,
        public string $patientPseudonym,
        public array $phiCategories,
        public string $purpose,
        public string $accessMethod,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'hipaa';
    }

    public function eventType(): string
    {
        return 'phi_accessed';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'accessor_identity' => $this->accessorIdentity,
            'patient_pseudonym' => $this->patientPseudonym,
            'phi_categories' => $this->phiCategories,
            'purpose' => $this->purpose,
            'access_method' => $this->accessMethod,
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     accessor_identity?: string,
     *     patient_pseudonym?: string,
     *     phi_categories?: list<string>,
     *     purpose?: string,
     *     access_method?: string,
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
            patientPseudonym: $data['patient_pseudonym'] ?? '',
            phiCategories: $data['phi_categories'] ?? [],
            purpose: $data['purpose'] ?? '',
            accessMethod: $data['access_method'] ?? '',
        );
    }
}
