<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Dto;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;

/**
 * Immutable DTO representing a registered AI model.
 *
 * Captures metadata required by ISO 42001:2023 Clause 8.2 for AI system
 * documentation including model cards, risk classification, and lifecycle state.
 */
#[Api(since: '1.0.0')]
final readonly class AiModel
{
    /**
     * @param non-empty-string $id Unique model identifier
     * @param non-empty-string $name Human-readable model name
     * @param non-empty-string $version Model version string
     * @param non-empty-string $provider Organization or service providing the model
     * @param non-empty-string $type Model type (e.g., 'llm', 'classifier', 'regressor', 'generative')
     * @param ModelCard|null $card Structured documentation of capabilities, limitations, biases
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public string $provider,
        public string $type,
        public AiModelRiskLevel $riskLevel,
        public AiModelStatus $status,
        public DateTimeImmutable $registeredAt,
        public ?ModelCard $card = null,
    ) {}

    /**
     * Create a new model with a different status.
     */
    #[NoDiscard]
    public function withStatus(AiModelStatus $status): self
    {
        return clone($this, ['status' => $status]);
    }

    /**
     * Create a new model with a different risk level.
     */
    #[NoDiscard]
    public function withRiskLevel(AiModelRiskLevel $riskLevel): self
    {
        return clone($this, ['riskLevel' => $riskLevel]);
    }

    /**
     * Create a new model with an attached model card.
     */
    #[NoDiscard]
    public function withCard(ModelCard $card): self
    {
        return clone($this, ['card' => $card]);
    }
}
