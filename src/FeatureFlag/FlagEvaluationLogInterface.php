<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag;

use Pulsar\Api\Api;

#[Api]
interface FlagEvaluationLogInterface
{
    public function record(FlagEvaluation $evaluation): void;

    /**
     * @return list<FlagEvaluation>
     */
    public function all(): array;

    /**
     * @return list<FlagEvaluation>
     */
    public function forFlag(string $flagName): array;

    public function clear(): void;

    public function count(): int;
}
