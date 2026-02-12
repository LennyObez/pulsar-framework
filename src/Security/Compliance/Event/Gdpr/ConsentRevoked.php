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
 * Records that a data subject has revoked consent for a specific processing purpose.
 *
 * Supports controls for GDPR Article 7(3) right to withdraw consent.
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class ConsentRevoked extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $subjectId,
        public string $purpose,
        public string $revocationReason,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'gdpr';
    }

    public function eventType(): string
    {
        return 'consent_revoked';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'subject_id' => $this->subjectId,
            'purpose' => $this->purpose,
            'revocation_reason' => $this->revocationReason,
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
            subjectId: is_string($data['subject_id'] ?? null) ? $data['subject_id'] : '',
            purpose: is_string($data['purpose'] ?? null) ? $data['purpose'] : '',
            revocationReason: is_string($data['revocation_reason'] ?? null) ? $data['revocation_reason'] : '',
        );
    }
}
