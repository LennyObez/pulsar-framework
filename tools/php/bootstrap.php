<?php

declare(strict_types=1);

/**
 * PHPUnit and PHPBench bootstrap.
 *
 * Loads Composer's autoloader, then registers PSR-4 autoloading for the
 * framework's bundled extensions. Extensions are intentionally NOT part of the
 * root composer.json autoload (ADR-0004: no privileged built-in access), so
 * this registration is what makes their `src/` classes resolvable to the test
 * suite. Test classes themselves remain mapped via composer.json autoload-dev.
 *
 * tools/php/phpbench.json points `runner.bootstrap` here for the same reason.
 * It used to point straight at vendor/autoload.php, and every benchmark under
 * tests/Benchmark/Cms — twelve files, twenty budgeted subjects — died in setUp
 * with "Class Pulsar\Extension\Cms\... not found" because this registration had
 * not run. PHPBench reported them as errors and exited non-zero; the CI step
 * piped that exit code into `tee` and threw it away, so the job kept passing.
 */

require __DIR__ . '/../../vendor/autoload.php';

// Anchor path helpers to the repository root for the whole suite, so tests get
// a consistent base_path() and — crucially — Kernel::boot()'s only-when-unset
// PULSAR_BASE_PATH anchoring becomes a no-op, preventing one test's temp-dir
// config root from leaking into every later test via the process env.
if (getenv('PULSAR_BASE_PATH') === false || getenv('PULSAR_BASE_PATH') === '') {
    putenv('PULSAR_BASE_PATH=' . dirname(__DIR__, 2));
}

Pulsar\Extensibility\ExtensionAutoloader::registerForPaths([
    __DIR__ . '/../../extensions',
]);
