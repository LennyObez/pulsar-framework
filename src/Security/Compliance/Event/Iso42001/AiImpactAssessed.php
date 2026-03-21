<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Iso42001;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_float;
use function is_int;
use function is_string;

/**
 * Records the completion of an AI impact assessment.
 *
 * Supports ISO 42001:2023 Clause 6.1.2 (AI risk assessment) and Clause 8.2
 * (AI system impact assessment) by capturing assessment outcomes.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class AiImpactAssessed extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $modelId,
        public float $riskScore,
        public int $findingsCount,
        public string $assessedBy,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'iso42001';
    }

    public function eventType(): string
    {
        return 'ai_impact_assessed';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'model_id' => $this->modelId,
            'risk_score' => $this->riskScore,
            'findings_count' => $this->findingsCount,
            'assessed_by' => $this->assessedBy,
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
            modelId: is_string($data['model_id'] ?? null) ? $data['model_id'] : '',
            riskScore: is_float($data['risk_score'] ?? null) ? $data['risk_score'] : 0.0,
            findingsCount: is_int($data['findings_count'] ?? null) ? $data['findings_count'] : 0,
            assessedBy: is_string($data['assessed_by'] ?? null) ? $data['assessed_by'] : '',
        );
    }
}
