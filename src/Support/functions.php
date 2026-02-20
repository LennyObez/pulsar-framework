<?php

declare(strict_types=1);

/**
 * Global helper functions for Pulsar DX.
 *
 * Autoloaded via composer.json `files` section, available everywhere.
 * Each function delegates to its static class counterpart.
 */

use Pulsar\ErrorHandling\HttpException;
use Pulsar\Http\ResponseStatus;
use Pulsar\Support\Collection;
use Pulsar\Support\Helpers;
use Pulsar\Support\Pipeline;

if (!function_exists('collect')) {
    /**
     * Create a new collection from an array.
     *
     * @template T
     *
     * @param list<T> $items
     *
     * @return Collection<T>
     */
    function collect(array $items = []): Collection
    {
        return Collection::of($items);
    }
}

if (!function_exists('value')) {
    /**
     * Resolve a value: call closures, pass scalars through.
     *
     * @template T
     *
     * @param T|\Closure(): T $value
     *
     * @return T
     */
    function value(mixed $value): mixed
    {
        return Helpers::value($value);
    }
}

if (!function_exists('retry')) {
    /**
     * Retry a callback with exponential backoff.
     *
     * @template T
     *
     * @param \Closure(): T $callback
     * @param int $times Maximum attempts (>= 1)
     * @param int $baseDelayMs Base delay in milliseconds
     * @param float $multiplier Delay multiplier per attempt
     * @param (\Closure(\Throwable): bool)|null $when Only retry when this returns true
     *
     * @return T
     */
    function retry(
        \Closure $callback,
        int $times = 3,
        int $baseDelayMs = 100,
        float $multiplier = 2.0,
        ?\Closure $when = null,
    ): mixed {
        return Helpers::retry($callback, $times, $baseDelayMs, $multiplier, $when);
    }
}

if (!function_exists('once')) {
    /**
     * Memoize a callable; return cached result on repeat calls.
     *
     * @template T
     *
     * @param \Closure(): T $callback
     *
     * @return \Closure(): T
     */
    function once(\Closure $callback): \Closure
    {
        return Helpers::once($callback);
    }
}

if (!function_exists('tap')) {
    /**
     * Apply a callback to a value, then return the original value.
     *
     * @template T
     *
     * @param T $value
     * @param (\Closure(T): void)|null $callback
     *
     * @return T
     */
    function tap(mixed $value, ?\Closure $callback = null): mixed
    {
        return Helpers::tap($value, $callback);
    }
}

if (!function_exists('pipeline')) {
    /**
     * Send data through a series of stages.
     */
    function pipeline(mixed $passable): Pipeline
    {
        return Pipeline::send($passable);
    }
}

if (!function_exists('abort')) {
    /**
     * Throw an HTTP exception with the given status code.
     *
     * @throws HttpException
     */
    function abort(int $code, string $message = ''): never
    {
        $status = ResponseStatus::tryFrom($code) ?? ResponseStatus::InternalServerError;

        throw new HttpException($status, $message);
    }
}

if (!function_exists('abort_if')) {
    /**
     * Throw an HTTP exception if the condition is true.
     *
     * @throws HttpException
     */
    function abort_if(bool $condition, int $code, string $message = ''): void
    {
        if ($condition) {
            abort($code, $message);
        }
    }
}

if (!function_exists('abort_unless')) {
    /**
     * Throw an HTTP exception unless the condition is true.
     *
     * @throws HttpException
     */
    function abort_unless(bool $condition, int $code, string $message = ''): void
    {
        if (!$condition) {
            abort($code, $message);
        }
    }
}

if (!function_exists('env')) {
    /**
     * Retrieve an environment variable with type coercion and default fallback.
     *
     * Reads from getenv() and coerces well-known string representations
     * of booleans, null, and empty to their native PHP types.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        if ($value === false) {
            return $default;
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

if (!function_exists('base_path')) {
    /**
     * Resolve an absolute path relative to the project root.
     *
     * Detection order (re-evaluated on every call):
     *  1. PULSAR_BASE_PATH environment variable (explicit override)
     *  2. `getcwd()` (standard CLI and FPM usage)
     *
     * No caching. In persistent runtimes (Swoole, FrankenPHP,
     * RoadRunner) a process-lifetime static would leak state
     * across requests if the worker ever rebases its CWD — the
     * overhead of one `getenv()` + `getcwd()` per call is
     * negligible vs the correctness guarantee (Mi-5 audit
     * response).
     *
     * @param string $path Optional path segment to append
     */
    function base_path(string $path = ''): string
    {
        $envBase = getenv('PULSAR_BASE_PATH');
        $basePath = ($envBase !== false && $envBase !== '') ? $envBase : (string) getcwd();

        return $basePath . ($path !== '' ? DIRECTORY_SEPARATOR . $path : '');
    }
}

if (!function_exists('storage_path')) {
    /**
     * Resolve an absolute path relative to the project's storage directory.
     *
     * @param string $path Optional path segment to append within storage/
     */
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path !== '' ? DIRECTORY_SEPARATOR . $path : ''));
    }
}

if (!function_exists('resource_path')) {
    /**
     * Resolve an absolute path relative to the project's resources directory.
     *
     * @param string $path Optional path segment to append within resources/
     */
    function resource_path(string $path = ''): string
    {
        return base_path('resources' . ($path !== '' ? DIRECTORY_SEPARATOR . $path : ''));
    }
}

if (!function_exists('config_path')) {
    /**
     * Resolve an absolute path relative to the project's config directory.
     *
     * @param string $path Optional path segment to append within config/
     */
    function config_path(string $path = ''): string
    {
        return base_path('config' . ($path !== '' ? DIRECTORY_SEPARATOR . $path : ''));
    }
}

if (!function_exists('public_path')) {
    /**
     * Resolve an absolute path relative to the project's public directory.
     *
     * @param string $path Optional path segment to append within public/
     */
    function public_path(string $path = ''): string
    {
        return base_path('public' . ($path !== '' ? DIRECTORY_SEPARATOR . $path : ''));
    }
}
