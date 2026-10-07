<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Sox;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

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
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     verifier_identity?: string,
     *     verification_period?: string,
     *     chain_integrity?: bool,
     *     entries_verified?: int,
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
            verificationPeriod: $data['verification_period'] ?? '',
            chainIntegrity: $data['chain_integrity'] ?? false,
            entriesVerified: $data['entries_verified'] ?? 0,
        );
    }
}
