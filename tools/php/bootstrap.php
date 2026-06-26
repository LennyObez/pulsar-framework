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

Pulsar\Extensibility\ExtensionAutoloader::registerForPaths([
    __DIR__ . '/../../extensions',
]);
