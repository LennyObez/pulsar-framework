<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Dora;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records completion of a third-party ICT provider risk assessment.
 *
 * Supports controls for DORA Article 28 third-party ICT service provider risk.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class ThirdPartyRiskAssessed extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param list<string> $findings
     */
    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $assessorIdentity,
        public string $providerName,
        public string $riskLevel,
        public array $findings,
        public DateTimeImmutable $nextReviewDate,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'dora';
    }

    public function eventType(): string
    {
        return 'third_party_risk_assessed';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'assessor_identity' => $this->assessorIdentity,
            'provider_name' => $this->providerName,
            'risk_level' => $this->riskLevel,
            'findings' => $this->findings,
            'next_review_date' => $this->nextReviewDate->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     assessor_identity?: string,
     *     provider_name?: string,
     *     risk_level?: string,
     *     findings?: list<string>,
     *     next_review_date?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $occurredAt = $data['occurred_at'] ?? null;
        $nextReviewDate = $data['next_review_date'] ?? null;

        return new self(
            eventId: $data['event_id'] ?? '',
            occurredAt: $occurredAt !== null ? new DateTimeImmutable($occurredAt) : new DateTimeImmutable(),
            correlationId: $data['correlation_id'] ?? '',
            nonce: $data['nonce'] ?? '',
            assessorIdentity: $data['assessor_identity'] ?? '',
            providerName: $data['provider_name'] ?? '',
            riskLevel: $data['risk_level'] ?? '',
            findings: $data['findings'] ?? [],
            nextReviewDate: $nextReviewDate !== null ? new DateTimeImmutable($nextReviewDate) : new DateTimeImmutable(),
        );
    }
}
