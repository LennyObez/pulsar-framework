<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Loads Composer's autoloader, then registers PSR-4 autoloading for the
 * framework's bundled extensions. Extensions are intentionally NOT part of the
 * root composer.json autoload (ADR-0004: no privileged built-in access), so
 * this registration is what makes their `src/` classes resolvable to the test
 * suite. Test classes themselves remain mapped via composer.json autoload-dev.
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
