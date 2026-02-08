<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use Pulsar\Api\Internal;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\CheckSeverity;
use Pulsar\Deploy\DeployCheckInterface;
use Pulsar\Deploy\DeploySeverity;

/**
 * Decorator that overrides the severity of a check result based on config.
 *
 * When config says 'warn', errors are downgraded to warnings.
 * When config says 'fail', warnings are upgraded to errors.
 * Passing results are never modified.
 */
#[Internal]
final readonly class SeverityOverrideCheck implements DeployCheckInterface
{
    public function __construct(
        private DeployCheckInterface $inner,
        private DeploySeverity $configuredSeverity,
    ) {}

    public function getName(): string
    {
        return $this->inner->getName();
    }

    public function getDescription(): string
    {
        return $this->inner->getDescription();
    }

    public function check(string $environment): CheckResult
    {
        $result = $this->inner->check($environment);

        // Passing results are never modified
        if ($result->severity === CheckSeverity::Pass) {
            return $result;
        }

        $targetSeverity = $this->configuredSeverity->toCheckSeverity();

        // If the configured severity matches the result, no change needed
        if ($result->severity === $targetSeverity) {
            return $result;
        }

        return new CheckResult(
            name: $result->name,
            severity: $targetSeverity,
            message: $result->message,
            recommendations: $result->recommendations,
        );
    }
}
