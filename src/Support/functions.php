<?php

declare(strict_types=1);

/**
 * Global helper functions for Pulsar DX.
 *
 * Autoloaded via composer.json `files` section, available everywhere.
 * Each function delegates to its static class counterpart.
 */

use Pulsar\Config\Environment;
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
     * A bootstrap-time helper. Resolution order:
     *   1. the active {@see Environment} (OS env + `.env`, merged, OS-wins,
     *      honouring the loader's allowlist) once ConfigManager has bound it;
     *   2. `getenv()` — for the pre-bootstrap window and unit tests that do not
     *      load configuration;
     *   3. the supplied default.
     *
     * This is what makes a value set only in `.env` resolve through `env()`
     * (see ADR-0033). Coerces the well-known string representations of booleans,
     * null, and empty to their native PHP types.
     *
     * Runtime application code should prefer typed config (ConfigManager / config
     * DTOs) or `Environment::get()` rather than `env()`.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $active = Environment::active();

        if ($active !== null) {
            $value = $active->get($key);

            if ($value === null) {
                return $default;
            }
        } else {
            $raw = getenv($key);

            if ($raw === false) {
                return $default;
            }

            $value = $raw;
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

if (!function_exists('var_path')) {
    /**
     * Resolve an absolute path relative to the project's `var/` directory — the
     * single writable root for all framework-managed state (cache, logs,
     * sessions, feature flags, the OpenAPI artifact, the integrity manifest and
     * the runtime pid). Ops mount `var/` as one writable volume; the
     * ephemeral-vs-durable distinction lives in the subdirectory names
     * (`var/cache` is disposable, `var/sessions` is not).
     *
     * @param string $path Optional path segment to append within var/
     */
    function var_path(string $path = ''): string
    {
        return base_path('var' . ($path !== '' ? DIRECTORY_SEPARATOR . $path : ''));
    }
}

if (!function_exists('resolve_path')) {
    /**
     * Resolve a (possibly relative) filesystem path to an absolute one.
     *
     * A relative path is resolved against the project root (see {@see base_path()});
     * a path that is already absolute is returned untouched, so an operator can
     * point a config value at a location outside the project tree (e.g. a shared
     * `/mnt/state` volume). This is the resolver every wiring should apply to a
     * config-driven path so the same value works regardless of the process CWD
     * (CLI, PHP-FPM, RoadRunner, FrankenPHP).
     *
     * @param string $path Relative or absolute path (empty yields the project root)
     */
    function resolve_path(string $path): string
    {
        if ($path === '') {
            return base_path();
        }

        if (
            $path[0] === '/' || $path[0] === '\\'
            || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1
        ) {
            return $path;
        }

        return base_path($path);
    }
}
