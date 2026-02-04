<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Runtime;

use Pulsar\Api\Api;

/**
 * Adapter for PHP runtime introspection functions.
 *
 * Wraps ini_get(), extension_loaded(), and function_exists() behind an
 * interface so deploy checks can be tested without environment manipulation.
 */
#[Api(since: '1.0.0')]
interface PhpRuntimeInterface
{
    /**
     * Get a configuration option value.
     *
     * @see ini_get()
     */
    public function iniGet(string $key): string|false;

    /**
     * Check whether an extension is loaded.
     *
     * @see extension_loaded()
     */
    public function extensionLoaded(string $name): bool;

    /**
     * Check whether a function is defined.
     *
     * @see function_exists()
     */
    public function functionExists(string $name): bool;
}
