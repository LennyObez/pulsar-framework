<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag;

use Pulsar\Api\Api;

/**
 * Interface for feature flag evaluation.
 */
#[Api]
interface FeatureFlagManagerInterface
{
    /**
     * Check if a feature flag is enabled.
     */
    public function isEnabled(string $flagName, ?FlagContext $context = null): bool;

    /**
     * Evaluate a flag and return the full evaluation record.
     */
    public function evaluate(string $flagName, ?FlagContext $context = null): FlagEvaluation;

    /**
     * Get all registered flag definitions.
     *
     * @return array<string, FlagDefinition>
     */
    public function allFlags(): array;
}
