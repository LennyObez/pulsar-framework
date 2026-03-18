<?php

declare(strict_types=1);

namespace Pulsar\Benchmark\Boot;

use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;

/**
 * PERF-BENCH-02 (external audit): boot-time cost of the ExtensionLoader's
 * filesystem scan + ExtensionBootstrap provider resolution.
 *
 * The audit flagged that ExtensionLoader uses a recursive DirectoryIterator
 * over `extensions/**` on every boot, then ExtensionBootstrap walks each
 * manifest's provider class through the container. The cost compounds as
 * Pulsar ships more extensions. This benchmark measures the steady-state
 * cost so the cache-compiled work (#119 PERF-HOT-04) has a concrete
 * baseline.
 *
 * Scaffold stage: the bench targets the real `extensions/` directory of
 * the project. Population (more synthetic extensions to amplify the
 * filesystem cost) is a follow-up if the measurement is too quiet.
 *
 * Run via:
 *   composer bench -- --filter=ExtensionBootstrapBench
 */
#[BeforeMethods('setUp')]
#[Revs(10)]
#[Iterations(5)]
#[Warmup(1)]
final class ExtensionBootstrapBench
{
    private string $extensionsPath;

    public function setUp(): void
    {
        // Use the real extensions/ directory. The bench is read-only on
        // disk, so we don't need a hermetic copy.
        $this->extensionsPath = dirname(__DIR__, 2) . '/extensions';
    }

    #[Subject]
    public function scanDirectory(): void
    {
        // Direct filesystem scan modelled on ExtensionLoader::scanDirectory().
        // The real ExtensionLoader needs ManifestException + dependency
        // wiring to be benched, which adds noise unrelated to the I/O cost.
        // This subject targets the pure scan cost — what would disappear
        // entirely under PERF-HOT-04 (compiled cache).
        $manifests = [];
        $iterator = new \DirectoryIterator($this->extensionsPath);

        foreach ($iterator as $item) {
            if ($item->isDot() || !$item->isDir()) {
                continue;
            }

            $manifestPath = $item->getPathname() . DIRECTORY_SEPARATOR . 'pulsar.json';

            if (file_exists($manifestPath)) {
                $manifests[] = $manifestPath;

                continue;
            }

            // Recurse one level (e.g., extensions/compliance/*).
            $inner = new \DirectoryIterator($item->getPathname());

            foreach ($inner as $sub) {
                if ($sub->isDot() || !$sub->isDir()) {
                    continue;
                }

                $subManifest = $sub->getPathname() . DIRECTORY_SEPARATOR . 'pulsar.json';

                if (file_exists($subManifest)) {
                    $manifests[] = $subManifest;
                }
            }
        }
    }
}
