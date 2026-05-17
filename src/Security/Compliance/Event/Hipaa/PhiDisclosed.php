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
 * Records disclosure of PHI to a third party.
 *
 * Supports controls for HIPAA Privacy Rule disclosure accounting.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class PhiDisclosed extends ComplianceEvent
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
        public string $discloserIdentity,
        public string $recipientIdentity,
        public string $patientPseudonym,
        public array $phiCategories,
        public string $legalBasis,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'hipaa';
    }

    public function eventType(): string
    {
        return 'phi_disclosed';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'discloser_identity' => $this->discloserIdentity,
            'recipient_identity' => $this->recipientIdentity,
            'patient_pseudonym' => $this->patientPseudonym,
            'phi_categories' => $this->phiCategories,
            'legal_basis' => $this->legalBasis,
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
            discloserIdentity: is_string($data['discloser_identity'] ?? null) ? $data['discloser_identity'] : '',
            recipientIdentity: is_string($data['recipient_identity'] ?? null) ? $data['recipient_identity'] : '',
            patientPseudonym: is_string($data['patient_pseudonym'] ?? null) ? $data['patient_pseudonym'] : '',
            phiCategories: $phiCategories,
            legalBasis: is_string($data['legal_basis'] ?? null) ? $data['legal_basis'] : '',
        );
    }
}
