<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Aml;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records detection of suspicious activity requiring SAR filing.
 *
 * Supports controls for AML suspicious activity reporting requirements.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class SuspiciousActivityDetected extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $detectorIdentity,
        public string $customerPseudonym,
        public string $activityType,
        public float $riskScore,
        public string $description,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'aml';
    }

    public function eventType(): string
    {
        return 'suspicious_activity_detected';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'detector_identity' => $this->detectorIdentity,
            'customer_pseudonym' => $this->customerPseudonym,
            'activity_type' => $this->activityType,
            'risk_score' => $this->riskScore,
            'description' => $this->description,
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     detector_identity?: string,
     *     customer_pseudonym?: string,
     *     activity_type?: string,
     *     risk_score?: float|int,
     *     description?: string,
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
            detectorIdentity: $data['detector_identity'] ?? '',
            customerPseudonym: $data['customer_pseudonym'] ?? '',
            activityType: $data['activity_type'] ?? '',
            riskScore: (float) ($data['risk_score'] ?? 0.0),
            description: $data['description'] ?? '',
        );
    }
}
