<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling\Fixture\PerformanceBudget\Breached;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;

use function usleep;

/**
 * A subject that misses its declared budget by three orders of magnitude.
 *
 * This is the planted regression for the Tier A hard gate: 77 #[Assert] budgets
 * across tests/Benchmark are only worth anything if a breached one stops the
 * build, and until now nothing had ever breached one on purpose to find out.
 *
 * It lives outside tests/Benchmark so `composer bench:ci` never collects it —
 * the gate test points phpbench at this directory with a config of its own.
 */
#[Revs(1)]
#[Iterations(1)]
#[Warmup(0)]
final class BreachedBudgetBench
{
    #[Subject]
    #[Assert('mode(variant.time.avg) < 1 microsecond')]
    public function benchOverItsBudget(): void
    {
        usleep(2_000);
    }
}
