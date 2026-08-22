<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Record of a feature flag evaluation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FlagEvaluation
{
    public function __construct(
        public string $flagName,
        public bool $result,
        public FlagEvaluationReason $reason,
        public FlagContext $context,
        public DateTimeImmutable $evaluatedAt,
    ) {}
}
