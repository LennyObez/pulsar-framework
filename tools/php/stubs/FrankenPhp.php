<?php

/**
 * Stub for the FrankenPHP SAPI functions (available only under the FrankenPHP
 * worker runtime).
 *
 * Provides type declarations for Psalm static analysis so the result is
 * identical whether or not the code runs under FrankenPHP. The real functions
 * are provided by the FrankenPHP runtime; the source always guards usage
 * behind function_exists().
 *
 * Coverage is scoped to the symbols used by:
 *   src/Runtime/FrankenPhpRuntime.php
 */

function frankenphp_handle_request(callable $callback): bool
{
    return false;
}

/**
 * @param array<string|int, string> $headers
 */
function frankenphp_early_hints(array $headers): void {}
