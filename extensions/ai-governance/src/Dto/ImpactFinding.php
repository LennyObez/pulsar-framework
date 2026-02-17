<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Dto;

use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Enum\ImpactCategory;
use Pulsar\Extension\AiGovernance\Enum\ImpactSeverity;

/**
 * A single finding from an AI impact assessment.
 *
 * Represents an identified risk, concern, or observation from evaluating
 * an AI system against ISO 42001:2023 impact categories.
 */
#[Api(since: '1.0.0')]
final readonly class ImpactFinding
{
    /**
     * @param non-empty-string $id Unique finding identifier
     * @param non-empty-string $title Short summary of the finding
     * @param non-empty-string $description Detailed explanation of the finding
     * @param non-empty-string $recommendation Recommended mitigation or action
     */
    public function __construct(
        public string $id,
        public ImpactCategory $category,
        public ImpactSeverity $severity,
        public string $title,
        public string $description,
        public string $recommendation,
    ) {}
}
