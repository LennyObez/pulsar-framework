<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Hipaa;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records completion of a HIPAA audit review.
 *
 * Supports controls for HIPAA Security Rule evaluation (45 CFR 164.308(a)(8)).
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class AuditReviewCompleted extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $reviewerIdentity,
        public string $reviewPeriod,
        public int $findingsCount,
        public int $criticalFindings,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'hipaa';
    }

    public function eventType(): string
    {
        return 'audit_review_completed';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'reviewer_identity' => $this->reviewerIdentity,
            'review_period' => $this->reviewPeriod,
            'findings_count' => $this->findingsCount,
            'critical_findings' => $this->criticalFindings,
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     reviewer_identity?: string,
     *     review_period?: string,
     *     findings_count?: int,
     *     critical_findings?: int,
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
            reviewerIdentity: $data['reviewer_identity'] ?? '',
            reviewPeriod: $data['review_period'] ?? '',
            findingsCount: $data['findings_count'] ?? 0,
            criticalFindings: $data['critical_findings'] ?? 0,
        );
    }
}
