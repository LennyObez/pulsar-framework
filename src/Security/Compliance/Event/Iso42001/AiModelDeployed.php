<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Iso42001;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_string;

/**
 * Records the deployment of an AI model to production.
 *
 * Supports ISO 42001:2023 Clause 8.4 (AI system lifecycle) and Clause 9.1
 * (monitoring and measurement) by capturing deployment decisions in the
 * compliance event stream.
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class AiModelDeployed extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $modelId,
        public string $modelName,
        public string $modelVersion,
        public string $riskLevel,
        public string $deployedBy,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'iso42001';
    }

    public function eventType(): string
    {
        return 'ai_model_deployed';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'model_id' => $this->modelId,
            'model_name' => $this->modelName,
            'model_version' => $this->modelVersion,
            'risk_level' => $this->riskLevel,
            'deployed_by' => $this->deployedBy,
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
            modelName: is_string($data['model_name'] ?? null) ? $data['model_name'] : '',
            modelVersion: is_string($data['model_version'] ?? null) ? $data['model_version'] : '',
            riskLevel: is_string($data['risk_level'] ?? null) ? $data['risk_level'] : '',
            deployedBy: is_string($data['deployed_by'] ?? null) ? $data['deployed_by'] : '',
        );
    }
}
