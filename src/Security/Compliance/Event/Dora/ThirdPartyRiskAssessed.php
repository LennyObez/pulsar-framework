<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Dora;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function array_values;
use function is_array;
use function is_string;

/**
 * Records completion of a third-party ICT provider risk assessment.
 *
 * Supports controls for DORA Article 28 third-party ICT service provider risk.
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var list<string> $findings */
        $findings = is_array($data['findings'] ?? null) ? array_values($data['findings']) : [];

        return new self(
            eventId: is_string($data['event_id'] ?? null) ? $data['event_id'] : '',
            occurredAt: is_string($data['occurred_at'] ?? null) ? new DateTimeImmutable($data['occurred_at']) : new DateTimeImmutable(),
            correlationId: is_string($data['correlation_id'] ?? null) ? $data['correlation_id'] : '',
            nonce: is_string($data['nonce'] ?? null) ? $data['nonce'] : '',
            assessorIdentity: is_string($data['assessor_identity'] ?? null) ? $data['assessor_identity'] : '',
            providerName: is_string($data['provider_name'] ?? null) ? $data['provider_name'] : '',
            riskLevel: is_string($data['risk_level'] ?? null) ? $data['risk_level'] : '',
            findings: $findings,
            nextReviewDate: is_string($data['next_review_date'] ?? null) ? new DateTimeImmutable($data['next_review_date']) : new DateTimeImmutable(),
        );
    }
}
