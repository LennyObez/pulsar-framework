<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Psd2;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_string;

/**
 * Records completion of a PSD2 transaction risk assessment.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class TransactionRiskAssessed extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $assessmentId,
        public string $transactionId,
        public string $riskLevel,
        public string $exemption,
        public bool $scaRequired,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'psd2';
    }

    public function eventType(): string
    {
        return 'transaction_risk_assessed';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'assessment_id' => $this->assessmentId,
            'transaction_id' => $this->transactionId,
            'risk_level' => $this->riskLevel,
            'exemption' => $this->exemption,
            'sca_required' => $this->scaRequired,
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
            assessmentId: is_string($data['assessment_id'] ?? null) ? $data['assessment_id'] : '',
            transactionId: is_string($data['transaction_id'] ?? null) ? $data['transaction_id'] : '',
            riskLevel: is_string($data['risk_level'] ?? null) ? $data['risk_level'] : '',
            exemption: is_string($data['exemption'] ?? null) ? $data['exemption'] : '',
            scaRequired: ($data['sca_required'] ?? false) === true,
        );
    }
}
