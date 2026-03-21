<?php

declare(strict_types=1);

namespace Pulsar\Deploy;

use Pulsar\Api\Api;

/**
 * Immutable report aggregating all deploy check results.
 */
#[Api(since: '1.0.0')]
final readonly class DeployReport
{
    /**
     * @param list<CheckResult> $results All individual check results
     * @param int $passed Number of checks that passed
     * @param int $warnings Number of checks with warnings
     * @param int $errors Number of checks with errors
     * @param string $environment The target environment these checks ran against
     */
    public function __construct(
        public array $results,
        public int $passed,
        public int $warnings,
        public int $errors,
        public string $environment,
    ) {}

    /**
     * Whether all checks passed without any warnings or errors.
     */
    public function allPassed(): bool
    {
        return $this->warnings === 0 && $this->errors === 0;
    }

    /**
     * Whether there are no error-severity results.
     *
     * Warnings are acceptable; only errors indicate a hard failure.
     */
    public function hasNoErrors(): bool
    {
        return $this->errors === 0;
    }

    /**
     * Get the total number of checks executed.
     */
    public function total(): int
    {
        return $this->passed + $this->warnings + $this->errors;
    }
}
