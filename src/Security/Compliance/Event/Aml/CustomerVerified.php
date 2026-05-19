<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Aml;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records completion of a customer verification (KYC).
 *
 * Supports controls for AML/KYC customer due diligence requirements.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class CustomerVerified extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param list<string> $documentTypes
     */
    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $verifierIdentity,
        public string $customerPseudonym,
        public string $verificationType,
        public string $verificationLevel,
        public array $documentTypes,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'aml';
    }

    public function eventType(): string
    {
        return 'customer_verified';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'verifier_identity' => $this->verifierIdentity,
            'customer_pseudonym' => $this->customerPseudonym,
            'verification_type' => $this->verificationType,
            'verification_level' => $this->verificationLevel,
            'document_types' => $this->documentTypes,
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     verifier_identity?: string,
     *     customer_pseudonym?: string,
     *     verification_type?: string,
     *     verification_level?: string,
     *     document_types?: list<string>,
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
            verifierIdentity: $data['verifier_identity'] ?? '',
            customerPseudonym: $data['customer_pseudonym'] ?? '',
            verificationType: $data['verification_type'] ?? '',
            verificationLevel: $data['verification_level'] ?? '',
            documentTypes: $data['document_types'] ?? [],
        );
    }
}
