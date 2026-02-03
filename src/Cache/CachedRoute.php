<?php

declare(strict_types=1);

namespace Pulsar\Cache;

use Pulsar\Api\Internal;
use Pulsar\Http\Method;

/**
 * Cache-safe representation of a Route.
 *
 * Mirrors Route exactly but replaces `mixed $handler` with
 * the typed RouteHandler for safe serialization.
 */
#[Internal]
readonly class CachedRoute
{
    /**
     * @param list<Method> $methods HTTP methods this route responds to
     * @param string $path URL path pattern
     * @param RouteHandler $handler Normalized handler for container resolution
     * @param string|null $name Optional route name
     * @param array<string, mixed> $attributes Route attributes
     * @param list<string> $middleware Middleware class names
     * @param array<string, string> $constraints Parameter constraints (regex patterns)
     * @param string|null $host Optional host constraint pattern
     */
    public function __construct(
        public array $methods,
        public string $path,
        public RouteHandler $handler,
        public ?string $name = null,
        public array $attributes = [],
        public array $middleware = [],
        public array $constraints = [],
        public ?string $host = null,
    ) {}
}
