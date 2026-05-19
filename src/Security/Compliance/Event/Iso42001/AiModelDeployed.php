<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Iso42001;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records the deployment of an AI model to production.
 *
 * Supports ISO 42001:2023 Clause 8.4 (AI system lifecycle) and Clause 9.1
 * (monitoring and measurement) by capturing deployment decisions in the
 * compliance event stream.
 * @api
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
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     model_id?: string,
     *     model_name?: string,
     *     model_version?: string,
     *     risk_level?: string,
     *     deployed_by?: string,
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
            modelId: $data['model_id'] ?? '',
            modelName: $data['model_name'] ?? '',
            modelVersion: $data['model_version'] ?? '',
            riskLevel: $data['risk_level'] ?? '',
            deployedBy: $data['deployed_by'] ?? '',
        );
    }
}
