<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Hipaa;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_int;
use function is_string;

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
            reviewerIdentity: is_string($data['reviewer_identity'] ?? null) ? $data['reviewer_identity'] : '',
            reviewPeriod: is_string($data['review_period'] ?? null) ? $data['review_period'] : '',
            findingsCount: is_int($data['findings_count'] ?? null) ? $data['findings_count'] : 0,
            criticalFindings: is_int($data['critical_findings'] ?? null) ? $data['critical_findings'] : 0,
        );
    }
}
