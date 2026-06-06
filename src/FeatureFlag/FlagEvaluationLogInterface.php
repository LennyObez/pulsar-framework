<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag;

use Pulsar\Api\Api;

#[Api(since: '1.0.0')]
interface FlagEvaluationLogInterface
{
    public function record(FlagEvaluation $evaluation): void;

    /**
     * Register an observer to be notified on every flag evaluation.
     *
     * @param callable(FlagEvaluation): void $observer
     */
    public function addObserver(callable $observer): void;

    /**
     * @return list<FlagEvaluation>
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function all(): array;

    /**
     * @return list<FlagEvaluation>
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function forFlag(string $flagName): array;

    public function clear(): void;

    public function count(): int;
}
