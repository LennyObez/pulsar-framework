<?php

declare(strict_types=1);

namespace Pulsar\Tests\Runner;

use Override;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

use function getenv;
use function putenv;

/**
 * Restores the PULSAR_BASE_PATH the suite started with, before every test.
 *
 * ## The defect this closes
 *
 * `tools/php/bootstrap.php` anchors PULSAR_BASE_PATH at the repository root for
 * the whole run, so every path helper resolves the same way in every test. That
 * anchor is a process global, and a process global holds only until some test
 * writes to it. One did: `EnvHelperTest::tearDown()` cleared the variable —
 * correctly, for its own purposes — and never put it back. From that test onward
 * the suite ran unanchored.
 *
 * Unanchored is not the same as harmless, because the next thing to boot a kernel
 * takes ownership of the variable: `Kernel::boot()` exports PULSAR_BASE_PATH from
 * its config directory's parent whenever nothing else has set it. So the first
 * kernel booted after `EnvHelperTest` — in practice `PerformanceBudgetGateTest`,
 * against the boot-benchmark fixture project — silently re-pointed the whole
 * process at `tests/Benchmark/Fixtures/BootProject`. Nine tests of
 * `BundledExtensionContractTest`, in a different suite, then died on a storage
 * path they never chose. Each of the three files involved passed on its own.
 *
 * ## Why this rather than fixing the one test
 *
 * `EnvHelperTest` is fixed too — a test that clears a variable it did not set is
 * a defect on its own terms. But fixing it makes this particular leak absent, not
 * impossible: the next test to reach for `putenv('PULSAR_BASE_PATH')` reopens it,
 * and the failure surfaces in a different suite, several minutes later, in tests
 * that are green in isolation. That is the shape of bug that makes a suite
 * untrustworthy rather than merely red.
 *
 * `PreparationStarted` fires before `setUp()`, so a test that deliberately points
 * the variable somewhere — `StorageWiringTest`, `WritablePathGuardTest`,
 * `CachedBootTest` — still wins for its own duration. What it no longer gets is
 * the ability to decide where the NEXT test resolves its paths.
 *
 * ## Why it restores rather than sets
 *
 * The captured value is whatever the environment holds once the bootstrap script
 * has run, including "unset". An operator running the suite with an explicit
 * PULSAR_BASE_PATH keeps it, and a run where nothing anchored it stays unanchored
 * — this makes every test start where the run started, which is a different claim
 * from every test starting at the repository root.
 *
 * {@see ResetActiveEnvironmentExtension} does the same job for
 * `Environment::active()`, the other process global config touches.
 */
final class RestoresBasePathAnchorExtension implements Extension
{
    /** The one variable every path helper in the framework reads. */
    public const string VARIABLE = 'PULSAR_BASE_PATH';

    #[Override]
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(
            new class (getenv(self::VARIABLE)) implements PreparationStartedSubscriber {
                public function __construct(private readonly false|string $anchor) {}

                #[Override]
                public function notify(PreparationStarted $event): void
                {
                    if ($this->anchor === false || $this->anchor === '') {
                        putenv(RestoresBasePathAnchorExtension::VARIABLE);

                        return;
                    }

                    putenv(RestoresBasePathAnchorExtension::VARIABLE . '=' . $this->anchor);
                }
            },
        );
    }
}
