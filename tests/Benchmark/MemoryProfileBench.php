<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Tests\Benchmark\Support\MemoryProfileRunner;

/**
 * Memory peak and allocation benchmarks.
 *
 * Memory peak budgets run in fresh PHP processes (one scenario per isolated
 * process) to ensure accurate memory_get_peak_usage(true) measurements.
 *
 * Allocation counts are advisory — logged but not gating.
 */
#[BeforeMethods('setUp')]
#[Revs(1)]
#[Iterations(3)]
#[Warmup(0)]
final class MemoryProfileBench
{
    private MemoryProfileRunner $runner;

    public function setUp(): void
    {
        $this->runner = new MemoryProfileRunner();
    }

    /**
     * Peak memory for anonymous JSON API request (hard gate: < 2 MB).
     *
     * Runs in a fresh PHP process for accurate peak measurement.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 30 seconds')]
    public function benchPeakMemoryAnonymous(): void
    {
        $this->runner->assertWithinBudget('memory_anonymous', 2_097_152);
    }

    /**
     * Peak memory for authenticated request with audit (hard gate: < 4 MB).
     *
     * Runs in a fresh PHP process for accurate peak measurement.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 30 seconds')]
    public function benchPeakMemoryAuthenticated(): void
    {
        $this->runner->assertWithinBudget('memory_authenticated', 4_194_304);
    }

    /**
     * Peak memory for compliance-event request (hard gate: < 6 MB).
     *
     * Runs in a fresh PHP process for accurate peak measurement.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 30 seconds')]
    public function benchPeakMemoryCompliance(): void
    {
        $this->runner->assertWithinBudget('memory_compliance', 6_291_456);
    }
}
