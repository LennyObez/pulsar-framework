<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Hipaa;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function array_values;
use function is_array;
use function is_string;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var list<string> $phiCategories */
        $phiCategories = is_array($data['phi_categories'] ?? null) ? array_values($data['phi_categories']) : [];

        return new self(
            eventId: is_string($data['event_id'] ?? null) ? $data['event_id'] : '',
            occurredAt: is_string($data['occurred_at'] ?? null) ? new DateTimeImmutable($data['occurred_at']) : new DateTimeImmutable(),
            correlationId: is_string($data['correlation_id'] ?? null) ? $data['correlation_id'] : '',
            nonce: is_string($data['nonce'] ?? null) ? $data['nonce'] : '',
            accessorIdentity: is_string($data['accessor_identity'] ?? null) ? $data['accessor_identity'] : '',
            patientPseudonym: is_string($data['patient_pseudonym'] ?? null) ? $data['patient_pseudonym'] : '',
            phiCategories: $phiCategories,
            purpose: is_string($data['purpose'] ?? null) ? $data['purpose'] : '',
            accessMethod: is_string($data['access_method'] ?? null) ? $data['access_method'] : '',
        );
    }
}
