<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Aml;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function array_values;
use function is_array;
use function is_string;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var list<string> $documentTypes */
        $documentTypes = is_array($data['document_types'] ?? null) ? array_values($data['document_types']) : [];

        return new self(
            eventId: is_string($data['event_id'] ?? null) ? $data['event_id'] : '',
            occurredAt: is_string($data['occurred_at'] ?? null) ? new DateTimeImmutable($data['occurred_at']) : new DateTimeImmutable(),
            correlationId: is_string($data['correlation_id'] ?? null) ? $data['correlation_id'] : '',
            nonce: is_string($data['nonce'] ?? null) ? $data['nonce'] : '',
            verifierIdentity: is_string($data['verifier_identity'] ?? null) ? $data['verifier_identity'] : '',
            customerPseudonym: is_string($data['customer_pseudonym'] ?? null) ? $data['customer_pseudonym'] : '',
            verificationType: is_string($data['verification_type'] ?? null) ? $data['verification_type'] : '',
            verificationLevel: is_string($data['verification_level'] ?? null) ? $data['verification_level'] : '',
            documentTypes: $documentTypes,
        );
    }
}
