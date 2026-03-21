<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Gdpr;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_string;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $expiresAt = is_string($data['expires_at'] ?? null)
            ? new DateTimeImmutable($data['expires_at'])
            : null;

        return new self(
            eventId: is_string($data['event_id'] ?? null) ? $data['event_id'] : '',
            occurredAt: is_string($data['occurred_at'] ?? null) ? new DateTimeImmutable($data['occurred_at']) : new DateTimeImmutable(),
            correlationId: is_string($data['correlation_id'] ?? null) ? $data['correlation_id'] : '',
            nonce: is_string($data['nonce'] ?? null) ? $data['nonce'] : '',
            subjectId: is_string($data['subject_id'] ?? null) ? $data['subject_id'] : '',
            purpose: is_string($data['purpose'] ?? null) ? $data['purpose'] : '',
            legalBasis: is_string($data['legal_basis'] ?? null) ? $data['legal_basis'] : '',
            consentScope: is_string($data['consent_scope'] ?? null) ? $data['consent_scope'] : '',
            expiresAt: $expiresAt,
        );
    }
}
