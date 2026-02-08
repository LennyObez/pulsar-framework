<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Pulsar\Api\Api;

/**
 * Route registration contract for extensions.
 *
 * Provides the HTTP verb convenience methods that extensions use
 * to register routes during the boot phase. Framework-internal
 * methods (match, lock, loadCachedRoutes, etc.) are intentionally
 * excluded to keep the extension-facing surface minimal.
 */
#[Api]
interface RouterInterface
{
    /**
     * Register a GET route.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public function get(string $path, mixed $handler, ?string $name = null): self;

    /**
     * Register a POST route.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public function post(string $path, mixed $handler, ?string $name = null): self;

    /**
     * Register a PUT route.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public function put(string $path, mixed $handler, ?string $name = null): self;

    /**
     * Register a PATCH route.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public function patch(string $path, mixed $handler, ?string $name = null): self;

    /**
     * Register a DELETE route.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public function delete(string $path, mixed $handler, ?string $name = null): self;

    /**
     * Register a route matching any method.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public function any(string $path, mixed $handler, ?string $name = null): self;
}
