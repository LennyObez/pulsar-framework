<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Hipaa;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

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
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     discloser_identity?: string,
     *     recipient_identity?: string,
     *     patient_pseudonym?: string,
     *     phi_categories?: list<string>,
     *     legal_basis?: string,
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
            discloserIdentity: $data['discloser_identity'] ?? '',
            recipientIdentity: $data['recipient_identity'] ?? '',
            patientPseudonym: $data['patient_pseudonym'] ?? '',
            phiCategories: $data['phi_categories'] ?? [],
            legalBasis: $data['legal_basis'] ?? '',
        );
    }
}
