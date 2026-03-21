<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Aml;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_float;
use function is_int;
use function is_string;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $riskScore = $data['risk_score'] ?? 0.0;
        if (is_int($riskScore)) {
            $riskScore = (float) $riskScore;
        }

        return new self(
            eventId: is_string($data['event_id'] ?? null) ? $data['event_id'] : '',
            occurredAt: is_string($data['occurred_at'] ?? null) ? new DateTimeImmutable($data['occurred_at']) : new DateTimeImmutable(),
            correlationId: is_string($data['correlation_id'] ?? null) ? $data['correlation_id'] : '',
            nonce: is_string($data['nonce'] ?? null) ? $data['nonce'] : '',
            detectorIdentity: is_string($data['detector_identity'] ?? null) ? $data['detector_identity'] : '',
            customerPseudonym: is_string($data['customer_pseudonym'] ?? null) ? $data['customer_pseudonym'] : '',
            activityType: is_string($data['activity_type'] ?? null) ? $data['activity_type'] : '',
            riskScore: is_float($riskScore) ? $riskScore : 0.0,
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
        );
    }
}
