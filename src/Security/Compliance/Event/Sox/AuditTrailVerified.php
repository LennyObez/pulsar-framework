<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Sox;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_bool;
use function is_int;
use function is_string;

/**
 * Records verification of audit trail integrity.
 *
 * Supports controls for SOX Section 802 preservation of audit records.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class AuditTrailVerified extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $verifierIdentity,
        public string $verificationPeriod,
        public bool $chainIntegrity,
        public int $entriesVerified,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'sox';
    }

    public function eventType(): string
    {
        return 'audit_trail_verified';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'verifier_identity' => $this->verifierIdentity,
            'verification_period' => $this->verificationPeriod,
            'chain_integrity' => $this->chainIntegrity,
            'entries_verified' => $this->entriesVerified,
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
            verifierIdentity: is_string($data['verifier_identity'] ?? null) ? $data['verifier_identity'] : '',
            verificationPeriod: is_string($data['verification_period'] ?? null) ? $data['verification_period'] : '',
            chainIntegrity: is_bool($data['chain_integrity'] ?? null) ? $data['chain_integrity'] : false,
            entriesVerified: is_int($data['entries_verified'] ?? null) ? $data['entries_verified'] : 0,
        );
    }
}
