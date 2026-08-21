<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Support;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\Kernel;
use Pulsar\Core\Wiring\WiringList;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewarePipelineInterface;
use RuntimeException;

use function count;
use function sprintf;

/**
 * Builds the kernel the boot benchmarks measure, and proves it is a booted one.
 *
 * `Kernel::boot()` gates the entire wiring loop behind `$this->configManager !==
 * null`, so `new Kernel()` — the shape the boot benchmarks used until now —
 * constructs a container, matches one route and returns, having executed none of
 * the 51 wirings in {@see WiringList::default()}. It measured about 200 us and
 * asserted under 500, which is why it could not see a boot regression: there was
 * no boot in it. Every kernel built here is given a {@see ConfigManager} pointed
 * at a fixture project, which is what puts the wiring loop on the measured path.
 *
 * {@see assertWiringsRan()} is the part that keeps it that way. A benchmark that
 * stops exercising its subject gets FASTER, so the assertion protecting it
 * passes harder the more broken the benchmark is — the failure mode is silent by
 * construction and no budget can detect it. The check therefore runs in setUp,
 * before any timing, and throws when the loop did not run end to end.
 */
final class BootBenchmarkKernel
{
    /**
     * The route the benchmarks dispatch against. Registered on the kernel's own
     * router rather than loaded from a route file, so the measurement covers the
     * framework's boot and not a fixture's routing table.
     */
    public const string ROUTE = '/bench';

    /**
     * Config directory of the fixture project.
     *
     * Laid out as a project (`BootProject/config/`) rather than a loose folder of
     * config files, because `Kernel::boot()` anchors `PULSAR_BASE_PATH` to
     * `dirname()` of this path when nothing else has set it — so under a bare
     * `php` process the fixture is self-contained. Under phpbench the runner
     * bootstrap has already anchored the variable at the repository root, which
     * `assertWiringsRan()` covers from the other side by refusing a boot that
     * loaded a framework cache.
     */
    public static function configPath(): string
    {
        return __DIR__ . '/../Fixtures/BootProject/config';
    }

    /**
     * Construct a kernel, boot it and dispatch one request.
     *
     * This is the single construction path: setUp calls it to obtain a kernel it
     * can verify, and the benchmark subject calls the same method. There is no
     * second, unverified way to build the measured kernel.
     */
    public function bootAndHandle(ServerRequestInterface $request): Kernel
    {
        $kernel = new Kernel(configManager: new ConfigManager(configPath: self::configPath()));
        $kernel->router()->get(self::ROUTE, static fn(): Response => Response::text('ok'));
        $kernel->handle($request);

        return $kernel;
    }

    /**
     * Fail unless the wiring loop ran from its first entry to its last.
     *
     * Four checks, each covering a way the measured boot can stop being one:
     *
     * 1. `AppConfig` — bound by `ConfigWiring`, the FIRST entry of
     *    {@see WiringList::default()}. Absent when the loop is skipped entirely,
     *    which is exactly what a null ConfigManager does.
     * 2. `ControlCatalog` — bound by `ComplianceCatalogWiring`, the LAST entry.
     *    Absent when the loop is entered but does not complete: a wiring
     *    throwing, an early return, or a change that runs only part of the list.
     *    Named explicitly rather than derived from the wirings' own contracts,
     *    because a `WiringContract::$provides` entry is frequently conditional on
     *    config (BroadcastWiring provides nothing when broadcasting is
     *    unconfigured) and a check that a correct boot can fail is not a check.
     *    This binding is registered unconditionally, so its absence means the
     *    last wiring did not run.
     * 3. A non-empty global middleware pipeline. The dispatch benchmark is only
     *    measuring a real request if the request traverses the stack the wirings
     *    piped; through an empty pipeline it measures a closure call.
     * 4. A cold boot. `Kernel::boot()` will serve config, container hints and the
     *    route table out of {@see \Pulsar\Cache\FrameworkCache} whenever
     *    PULSAR_MASTER_KEY is set and a cache exists under the base path — which
     *    skips the config load and shortens the wiring loop's work. That is a
     *    legitimate production mode and a fine thing to benchmark, but it is not
     *    the mode these budgets were derived from, and a warm cache appearing in
     *    someone's environment would silently make the benchmark faster. Refuse
     *    it rather than measure something else under the same budget.
     *
     * @throws RuntimeException when the kernel handed in did not run the wirings
     */
    public function assertWiringsRan(Kernel $kernel): void
    {
        $container = $kernel->container();

        if (!$container->has(AppConfig::class)) {
            throw new RuntimeException(
                'Boot benchmark is not measuring a boot: AppConfig is unbound, so ConfigWiring — '
                . 'the first entry of WiringList::default() — never ran. The kernel was built without '
                . 'a ConfigManager, and Kernel::boot() skips the whole wiring loop in that case.',
            );
        }

        if (!$container->has(ControlCatalog::class)) {
            $wirings = WiringList::default();

            throw new RuntimeException(sprintf(
                'Boot benchmark is measuring a truncated boot: %s is unbound, so %s — the last of '
                . 'the %d entries of WiringList::default() — did not run. If that wiring no longer '
                . 'binds it, point this check at another binding the final entry registers '
                . 'unconditionally; do not delete the check.',
                ControlCatalog::class,
                $wirings[count($wirings) - 1]::class,
                count($wirings),
            ));
        }

        $pipeline = $container->get(MiddlewarePipelineInterface::class);

        if (!$pipeline instanceof MiddlewarePipeline) {
            throw new RuntimeException(sprintf(
                'Boot benchmark cannot count the global middleware pipeline: expected %s, got %s.',
                MiddlewarePipeline::class,
                $pipeline::class,
            ));
        }

        if ($pipeline->count() === 0) {
            throw new RuntimeException(
                'Boot benchmark is not measuring a real request: the global middleware pipeline is '
                . 'empty, so the dispatch under measurement traverses nothing the wirings piped.',
            );
        }

        $profile = $kernel->bootProfile();

        if ($profile === null) {
            throw new RuntimeException(
                'Boot benchmark cannot confirm the boot happened: the kernel reports no BootProfile.',
            );
        }

        if ($profile->cacheHit || $profile->routesCached) {
            throw new RuntimeException(sprintf(
                'Boot benchmark is measuring a warm boot, and the budgets were derived from a cold '
                . 'one (config cache hit: %s, route cache hit: %s). The framework cache is only '
                . 'consulted when PULSAR_MASTER_KEY is set; unset it for this run, or clear the '
                . 'cache under the base path, rather than comparing a cached boot to a cold budget.',
                $profile->cacheHit ? 'yes' : 'no',
                $profile->routesCached ? 'yes' : 'no',
            ));
        }
    }
}
