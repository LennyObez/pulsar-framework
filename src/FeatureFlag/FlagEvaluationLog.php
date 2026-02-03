<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag;

use function array_filter;
use function array_values;
use function count;

/**
 * In-memory log of feature flag evaluations.
 */
final class FlagEvaluationLog
{
    /** @var list<FlagEvaluation> */
    private array $evaluations = [];

    /**
     * Record a flag evaluation.
     */
    public function record(FlagEvaluation $evaluation): void
    {
        $this->evaluations[] = $evaluation;
    }

    /**
     * Get all recorded evaluations.
     *
     * @return list<FlagEvaluation>
     */
    public function all(): array
    {
        return $this->evaluations;
    }

    /**
     * Get evaluations for a specific flag.
     *
     * @return list<FlagEvaluation>
     */
    public function forFlag(string $flagName): array
    {
        return array_values(array_filter(
            $this->evaluations,
            static fn(FlagEvaluation $e): bool => $e->flagName === $flagName,
        ));
    }

    /**
     * Clear all recorded evaluations.
     */
    public function clear(): void
    {
        $this->evaluations = [];
    }

    /**
     * Get the number of recorded evaluations.
     */
    public function count(): int
    {
        return count($this->evaluations);
    }
}
