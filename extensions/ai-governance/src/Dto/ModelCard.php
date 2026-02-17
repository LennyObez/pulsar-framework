<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Dto;

use Pulsar\Api\Api;

/**
 * Structured documentation of an AI model's characteristics.
 *
 * Model cards are required by ISO 42001:2023 Clause 8.2 for AI system
 * documentation and Annex A control A.7.5 for documentation of AI systems.
 *
 * @see https://arxiv.org/abs/1810.03993 Model Cards for Model Reporting
 */
#[Api(since: '1.0.0')]
final readonly class ModelCard
{
    /**
     * @param non-empty-string $description What the model does
     * @param non-empty-string $intendedUse Intended use cases and users
     * @param list<non-empty-string> $capabilities What the model can do
     * @param list<non-empty-string> $limitations Known limitations
     * @param list<non-empty-string> $knownBiases Documented biases
     * @param list<non-empty-string> $trainingDataSources Summary of training data origins
     * @param array<string, mixed> $performanceMetrics Key performance metrics
     * @param list<non-empty-string> $ethicalConsiderations Ethical considerations for deployment
     */
    public function __construct(
        public string $description,
        public string $intendedUse,
        public array $capabilities = [],
        public array $limitations = [],
        public array $knownBiases = [],
        public array $trainingDataSources = [],
        public array $performanceMetrics = [],
        public array $ethicalConsiderations = [],
    ) {}
}
