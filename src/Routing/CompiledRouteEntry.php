<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Pulsar\Api\Api;
use Pulsar\Http\Method;

/**
 * Serializable route entry for the compiled route tree.
 *
 * Contains all data needed to reconstruct a Route at runtime
 * without carrying closures or non-serializable handlers.
 */
#[Api(since: '1.0.0')]
final readonly class CompiledRouteEntry
{
    /**
     * @param list<string> $methods HTTP method values
     * @param string $path Original route path pattern
     * @param string|array{0: class-string, 1: string} $handler Class-string or [class, method]
     * @param string|null $name Route name
     * @param array<string, mixed> $attributes
     * @param list<string> $middleware
     * @param array<string, string> $constraints
     * @param string|null $host Host pattern
     */
    public function __construct(
        public array $methods,
        public string $path,
        public string|array $handler,
        public ?string $name = null,
        public array $attributes = [],
        public array $middleware = [],
        public array $constraints = [],
        public ?string $host = null,
    ) {}

    /**
     * Reconstruct a Route from this compiled entry.
     */
    public function toRoute(): Route
    {
        $methods = array_map(
            static fn(string $m): Method => Method::from($m),
            $this->methods,
        );

        return new Route(
            methods: $methods,
            path: $this->path,
            handler: $this->handler,
            name: $this->name,
            attributes: $this->attributes,
            middleware: $this->middleware,
            constraints: $this->constraints,
            host: $this->host,
        );
    }
}
