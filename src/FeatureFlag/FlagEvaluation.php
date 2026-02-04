<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag;

use DateTimeImmutable;

/**
 * Record of a feature flag evaluation.
 */
readonly class FlagEvaluation
{
    public function __construct(
        public string $flagName,
        public bool $result,
        public FlagEvaluationReason $reason,
        public FlagContext $context,
        public DateTimeImmutable $evaluatedAt,
    ) {}
}
