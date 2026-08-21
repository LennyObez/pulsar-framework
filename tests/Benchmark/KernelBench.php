<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\RetryThreshold;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Core\Kernel;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Tests\Benchmark\Support\BootBenchmarkKernel;

/**
 * Boot and dispatch budgets for a kernel that actually runs the framework's wirings.
 *
 * WHAT THIS USED TO MEASURE
 * -------------------------
 * Both subjects built their kernel with `new Kernel()`. `Kernel::boot()` gates
 * the whole wiring loop behind `$this->configManager !== null`, so that kernel
 * ran ZERO of the 51 entries in `WiringList::default()`: no config load, no
 * compiler passes over anything, no middleware piped. The boot subject measured
 * ~200 us of object construction and asserted under 500, and the dispatch subject
 * measured ~12 us through an EMPTY middleware pipeline and asserted under 200.
 * Both passed with 2.5x and 16x headroom while being structurally incapable of
 * seeing a boot regression, because the thing that costs the boot its time — the
 * wiring loop, ~98% of it — was not on the measured path at all. Adding a wiring
 * that took a second could not have moved either number. A gate in a job called
 * 'Tier A: Performance Budgets (Hard Gate)' that cannot fail is worse than no
 * gate: it reports a budget nobody is paying.
 *
 * Both subjects now build through {@see BootBenchmarkKernel}, which supplies a
 * ConfigManager, and setUp calls `assertWiringsRan()` on the very kernel the
 * subjects use. If the wiring loop is ever skipped or truncated again, setUp
 * throws instead of the benchmark quietly getting faster.
 *
 * WHERE THE BUDGETS COME FROM
 * ---------------------------
 * Measured on 2026-08-21 with `phpbench run --config=tools/php/phpbench.json
 * --filter=KernelBench`, five iterations per subject, six runs, on:
 *
 *   Intel Core i7-4790 @ 3.60 GHz (4 cores / 8 threads), Windows 11 Pro 26100,
 *   PHP 8.5.9 ZTS, OPcache on, JIT off, Xdebug off — the interpreter settings
 *   `runner.php_config` in tools/php/phpbench.json pins, so this measurement is
 *   reproducible rather than a property of one developer's php.ini. Xdebug alone
 *   moved these two numbers by 2.2x and 8.6x when it was left loaded, which is
 *   why the pin is there and why no measurement taken without it is comparable.
 *
 *   benchKernelBootAndHandle    per-iteration time_avg 6.8 - 13.8 ms across 30
 *                               iterations, median ~9.7 ms; reported mode 8.2 - 12.8 ms
 *   benchPreBootedKernelHandle  per-iteration time_avg 78.6 - 145.5 us across 30
 *                               iterations, median ~113 us; reported mode 85 - 138 us
 *
 * That spread is wide because the machine was not dedicated to the measurement;
 * the numbers are reported as a range rather than as one figure precisely so the
 * margin below is checkable against what was actually seen, and the budgets are
 * pinned to the WORST of it rather than to the prettiest reading. The whole file
 * takes ~32 s wall on that machine, ten processes, which is what the revolution
 * counts below are sized for.
 *
 * The kernel measured runs 51 wirings and pipes 4 global middleware. For scale:
 * the same boot with `new Kernel()` and no ConfigManager measured 208 us, so the
 * wiring loop these budgets now cover is ~98% of what a real boot costs, and none
 * of it was covered before.
 *
 * The budgets are 30 ms and 400 us: roughly 2.2x and 2.7x the WORST iteration
 * seen, ~3x the median. That margin is for runner hardware and runner noise, and
 * it is deliberately stated rather than hidden — the machine above is a 2014
 * Haswell desktop, so a GitHub ubuntu-latest runner should not be materially
 * slower per core, but nothing here has run on one yet. What the margin buys is
 * a gate that catches anything adding ~20 ms to boot: a subsystem constructed
 * eagerly, a wiring doing I/O, a catalog built when no report was asked for.
 * What it costs is that a 10% drift passes. Taking the same measurement on this
 * box while it was under concurrent load produced 17.8 - 63.3 ms, which is what
 * happens when a benchmark shares a machine, and is the reason the margin is not
 * tighter. If a measurement on the enforcing hardware says a different number,
 * re-derive both budgets from it and replace the figures above; do not relax
 * them to make a red build green.
 */
#[BeforeMethods('setUp')]
#[Iterations(5)]
#[Warmup(1)]
final class KernelBench
{
    private BootBenchmarkKernel $factory;
    private ServerRequestInterface $request;
    private Kernel $bootedKernel;

    /**
     * Build one kernel through the same path the subjects use, and refuse to
     * benchmark it unless it really booted.
     *
     * The warmup revolution phpbench runs before timing already absorbs the
     * one-off cost of autoloading the framework, so this extra boot only pays
     * for the verification.
     */
    public function setUp(): void
    {
        $this->factory = new BootBenchmarkKernel();
        $this->request = new ServerRequest(method: 'GET', uri: BootBenchmarkKernel::ROUTE);

        $this->bootedKernel = $this->factory->bootAndHandle($this->request);
        $this->factory->assertWiringsRan($this->bootedKernel);
    }

    /**
     * Cold boot plus the first request: config load, the 51-wiring loop, the
     * compiler passes, route registration and one dispatch.
     *
     * Ten revolutions rather than the file-wide default of 1000: at ~10 ms each,
     * 1000 would be a minute and a half per iteration, and five iterations of
     * that does not belong in a 20-minute job.
     *
     * The retry threshold is raised above the repository-wide 20% because phpbench
     * retries a rejected iteration in a `while ($variant->getRejectCount() > 0)`
     * loop with no limit — `SubjectMetadata::setRetryLimit()` is never called
     * anywhere in phpbench 1.7 — so a subject that cannot settle inside the
     * threshold does not fail, it spins until the job's timeout kills it. A whole
     * framework boot is the widest-spread subject in the suite: 9-15% rstdev with
     * the machine quiet, and this subject reached 63 ms under load. A tight
     * threshold here would not be a stricter gate, it would be a hang. What gates
     * this subject is the budget below.
     */
    #[Subject]
    #[Revs(10)]
    #[RetryThreshold(25)]
    #[Assert('mode(variant.time.avg) < 30 milliseconds')]
    public function benchKernelBootAndHandle(): void
    {
        $this->factory->bootAndHandle($this->request);
    }

    /**
     * Steady-state dispatch on an already-booted kernel — what a persistent
     * worker pays per request, through the global middleware the wirings piped.
     *
     * Takes the repository-wide retry threshold rather than one of its own: it
     * came in between 10% and 26% rstdev across runs here and settled inside 20%
     * after a handful of retries, which is the behaviour that threshold is for.
     */
    #[Subject]
    #[Revs(500)]
    #[Assert('mode(variant.time.avg) < 400 microseconds')]
    public function benchPreBootedKernelHandle(): void
    {
        $this->bootedKernel->handle($this->request);
    }
}
