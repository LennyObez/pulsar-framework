<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Gdpr;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records that a data subject has granted consent for a specific processing purpose.
 *
 * Supports controls for GDPR Article 7 consent management.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class ConsentGranted extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $subjectId,
        public string $purpose,
        public string $legalBasis,
        public string $consentScope,
        public ?DateTimeImmutable $expiresAt,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'gdpr';
    }

    public function eventType(): string
    {
        return 'consent_granted';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'subject_id' => $this->subjectId,
            'purpose' => $this->purpose,
            'legal_basis' => $this->legalBasis,
            'consent_scope' => $this->consentScope,
            'expires_at' => $this->expiresAt?->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     subject_id?: string,
     *     purpose?: string,
     *     legal_basis?: string,
     *     consent_scope?: string,
     *     expires_at?: string|null,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $occurredAt = $data['occurred_at'] ?? null;
        $expiresAt = $data['expires_at'] ?? null;

        return new self(
            eventId: $data['event_id'] ?? '',
            occurredAt: $occurredAt !== null ? new DateTimeImmutable($occurredAt) : new DateTimeImmutable(),
            correlationId: $data['correlation_id'] ?? '',
            nonce: $data['nonce'] ?? '',
            subjectId: $data['subject_id'] ?? '',
            purpose: $data['purpose'] ?? '',
            legalBasis: $data['legal_basis'] ?? '',
            consentScope: $data['consent_scope'] ?? '',
            expiresAt: $expiresAt !== null ? new DateTimeImmutable($expiresAt) : null,
        );
    }
}
