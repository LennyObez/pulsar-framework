<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Psd2;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

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
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     assessment_id?: string,
     *     transaction_id?: string,
     *     risk_level?: string,
     *     exemption?: string,
     *     sca_required?: bool,
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
            assessmentId: $data['assessment_id'] ?? '',
            transactionId: $data['transaction_id'] ?? '',
            riskLevel: $data['risk_level'] ?? '',
            exemption: $data['exemption'] ?? '',
            scaRequired: ($data['sca_required'] ?? false) === true,
        );
    }
}
