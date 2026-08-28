<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling\Fixture\PerformanceBudget\Within;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;

/**
 * The control for the breached subject next door: a budget that is met.
 *
 * Without it, "phpbench exits non-zero on the breached fixture" is equally
 * explained by a runner that cannot start at all — which would make the Tier A
 * gate red for every change and, on the first person to add `|| true`, green for
 * all of them.
 */
#[Revs(1)]
#[Iterations(1)]
#[Warmup(0)]
final class WithinBudgetBench
{
    #[Subject]
    #[Assert('mode(variant.time.avg) < 1 second')]
    public function benchWithinItsBudget(): void
    {
        // Deliberately empty: what is under test is the assertion machinery, not
        // the speed of anything this repository ships.
    }
}
