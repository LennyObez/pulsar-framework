<?php

declare(strict_types=1);

namespace Pulsar\Supervisor\InvariantCheck;

use Pulsar\Api\Internal;

/**
 * Executes all registered invariant checks and aggregates the results.
 */
#[Internal]
final class InvariantRunner
{
    /** @var list<InvariantCheckInterface> */
    private readonly array $checks;

    /**
     * @param list<InvariantCheckInterface> $checks
     */
    public function __construct(array $checks)
    {
        $this->checks = $checks;
    }

    /**
     * Run every registered invariant check.
     *
     * @return list<InvariantCheckResult>
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
        foreach ($this->checks as $check) {
            if (!$check->check()->passed) {
                return false;
            }
        }

        return true;
    }
}
