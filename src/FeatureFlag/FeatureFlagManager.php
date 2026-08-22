<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag;

use DateTimeImmutable;
use Override;

use function crc32;
use function in_array;

/**
 * Feature flag evaluation engine.
 *
 * Supports boolean, percentage (deterministic hash-based), and contextual flags.
 * Records evaluations to the evaluation log for audit purposes.
 */
final readonly class FeatureFlagManager implements FeatureFlagManagerInterface
{
    public function __construct(
        private FlagStorageInterface $storage,
        private FlagEvaluationLog $log,
        private bool $defaultState = false,
    ) {}

    #[Override]
    public function isEnabled(string $flagName, ?FlagContext $context = null): bool
    {
        return $this->evaluate($flagName, $context)->result;
    }

    #[Override]
    public function evaluate(string $flagName, ?FlagContext $context = null): FlagEvaluation
    {
        $context ??= new FlagContext();
        $flag = $this->storage->get($flagName);

        if ($flag === null) {
            $evaluation = new FlagEvaluation(
                flagName: $flagName,
                result: $this->defaultState,
                reason: FlagEvaluationReason::FlagNotFound,
                context: $context,
                evaluatedAt: new DateTimeImmutable(),
            );
            $this->log->record($evaluation);

            return $evaluation;
        }

        if (!$flag->enabled) {
            $evaluation = new FlagEvaluation(
                flagName: $flagName,
                result: false,
                reason: FlagEvaluationReason::FlagDisabled,
                context: $context,
                evaluatedAt: new DateTimeImmutable(),
            );
            $this->log->record($evaluation);

            return $evaluation;
        }

        $evaluation = match ($flag->type) {
            FlagType::Boolean => $this->evaluateBoolean($flag, $context),
            FlagType::Percentage => $this->evaluatePercentage($flag, $context),
            FlagType::Contextual => $this->evaluateContextual($flag, $context),
        };

        $this->log->record($evaluation);

        return $evaluation;
    }

    #[Override]
    public function allFlags(): array
    {
        return $this->storage->all();
    }

    private function evaluateBoolean(FlagDefinition $flag, FlagContext $context): FlagEvaluation
    {
        return new FlagEvaluation(
            flagName: $flag->name,
            result: true,
            reason: FlagEvaluationReason::FlagEnabled,
            context: $context,
            evaluatedAt: new DateTimeImmutable(),
        );
    }

    private function evaluatePercentage(FlagDefinition $flag, FlagContext $context): FlagEvaluation
    {
        $identifier = $context->userId ?? $context->tenantId ?? '';
        $hash = crc32($flag->name . $identifier);
        $bucket = (($hash % 100) + 100) % 100;

        $result = $bucket < $flag->percentage;

        return new FlagEvaluation(
            flagName: $flag->name,
            result: $result,
            reason: $result ? FlagEvaluationReason::PercentageRollout : FlagEvaluationReason::PercentageExcluded,
            context: $context,
            evaluatedAt: new DateTimeImmutable(),
        );
    }

    private function evaluateContextual(FlagDefinition $flag, FlagContext $context): FlagEvaluation
    {
        // Check tenant match
        if ($context->tenantId !== null && $flag->allowedTenants !== []) {
            if (in_array($context->tenantId, $flag->allowedTenants, true)) {
                return new FlagEvaluation(
                    flagName: $flag->name,
                    result: true,
                    reason: FlagEvaluationReason::TenantMatch,
                    context: $context,
                    evaluatedAt: new DateTimeImmutable(),
                );
            }
        }

        // Check user match
        if ($context->userId !== null && $flag->allowedUsers !== []) {
            if (in_array($context->userId, $flag->allowedUsers, true)) {
                return new FlagEvaluation(
                    flagName: $flag->name,
                    result: true,
                    reason: FlagEvaluationReason::UserMatch,
                    context: $context,
                    evaluatedAt: new DateTimeImmutable(),
                );
            }
        }

        // Check environment match
        if ($context->environment !== null && $flag->allowedEnvironments !== []) {
            if (in_array($context->environment, $flag->allowedEnvironments, true)) {
                return new FlagEvaluation(
                    flagName: $flag->name,
                    result: true,
                    reason: FlagEvaluationReason::EnvironmentMatch,
                    context: $context,
                    evaluatedAt: new DateTimeImmutable(),
                );
            }
        }

        // No context matched: use default
        return new FlagEvaluation(
            flagName: $flag->name,
            result: $this->defaultState,
            reason: FlagEvaluationReason::DefaultState,
            context: $context,
            evaluatedAt: new DateTimeImmutable(),
        );
    }
}
