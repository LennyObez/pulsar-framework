<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Pulsar\Api\Api;

/**
 * Represents a successfully matched route.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MatchedRoute
{
    /**
     * @param Route $route The matched route definition
     * @param array<string, string> $parameters Extracted route parameters
     */
    public function __construct(
        public Route $route,
        public array $parameters = [],
    ) {}

    /**
     * Get a parameter value.
     */
    public function parameter(string $name, ?string $default = null): ?string
    {
        return $this->parameters[$name] ?? $default;
    }

    /**
     * Check if a parameter exists.
     */
    public function hasParameter(string $name): bool
    {
        return isset($this->parameters[$name]);
    }

    /**
     * Get the route handler.
     */
    public function getHandler(): mixed
    {
        return $this->route->handler;
    }

    /**
     * Get the route name.
     */
    public function getName(): ?string
    {
        return $this->route->name;
    }

    /**
     * Get route middleware.
     *
     * @return list<string>
     */
    public function getMiddleware(): array
    {
        return $this->route->middleware;
    }

    /**
     * Get route attributes.
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->route->attributes;
    }
}
