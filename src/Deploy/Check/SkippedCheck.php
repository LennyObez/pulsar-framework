<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use Pulsar\Api\Internal;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\CheckSeverity;
use Pulsar\Deploy\DeployCheckInterface;

use function sprintf;

/**
 * Stub check for when a required dependency is not configured.
 *
 * Reports a warning (or error in production) with the skip reason.
 */
#[Internal]
final readonly class SkippedCheck implements DeployCheckInterface
{
    public function __construct(
        private string $name,
        private string $reason,
        private CheckSeverity $productionSeverity = CheckSeverity::Warning,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return sprintf('Skipped: %s', $this->reason);
    }

    public function check(string $environment): CheckResult
    {
        $message = sprintf('SKIPPED: %s', $this->reason);

        if ($environment === 'production') {
            return new CheckResult(
                name: $this->name,
                severity: $this->productionSeverity,
                message: $message,
                recommendations: ['Configure the required dependency to enable this check.'],
            );
        }

        return CheckResult::warning($this->name, $message);
    }
}
