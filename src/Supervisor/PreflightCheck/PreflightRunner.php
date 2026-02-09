<?php

declare(strict_types=1);

namespace Pulsar\Supervisor\PreflightCheck;

use Pulsar\Api\Internal;

/**
 * Executes all registered preflight checks and aggregates the results.
 */
#[Internal]
final readonly class PreflightRunner implements PreflightRunnerInterface
{
    /** @var list<PreflightCheckInterface> */
    private array $checks;

    /**
     * @param list<PreflightCheckInterface> $checks
     */
    public function __construct(array $checks)
    {
        $this->checks = $checks;
    }

    /**
     * Run every registered preflight check.
     *
     * @return list<PreflightCheckResult>
     */
    public function run(): array
    {
        $results = [];

        foreach ($this->checks as $check) {
            $results[] = $check->check();
        }

        return $results;
    }

    /**
     * Run all checks and return whether every one passed.
     */
    public function allPassed(): bool
    {
        return array_all($this->checks, static fn(PreflightCheckInterface $check): bool => $check->check()->passed);
    }
}
