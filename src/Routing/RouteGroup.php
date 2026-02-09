<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Pulsar\Api\Api;

/**
 * Groups related routes with a common prefix and attributes.
 */
#[Api(since: '1.0.0')]
final class RouteGroup
{
    /**
     * @var list<Route|RouteGroup>
     */
    private array $routes = [];

    /**
     * @param string $prefix URL prefix for all routes in this group
     * @param list<string> $middleware Middleware to apply to all routes
     * @param array<string, mixed> $attributes Additional attributes for all routes
     * @param string|null $host Host pattern applied to all routes in this group
     */
    public function __construct(
        private readonly string $prefix = '',
        private readonly array $middleware = [],
        private readonly array $attributes = [],
        private readonly ?string $host = null,
    ) {}

    /**
     * Add a route to this group.
     */
    public function add(Route $route): self
    {
        $this->routes[] = $route;
        return $this;
    }

    /**
     * Add a nested group to this group.
     */
    public function group(RouteGroup $group): self
    {
        $this->routes[] = $group;
        return $this;
    }

    /**
     * Get the prefix for this group.
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Get the middleware for this group.
     *
     * @return list<string>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Get the attributes for this group.
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Flatten all routes in this group with their prefixes and middleware applied.
     *
     * @param string $parentPrefix Prefix from parent groups
     * @param list<string> $parentMiddleware Middleware from parent groups
     * @param array<string, mixed> $parentAttributes Attributes from parent groups
     * @return list<Route>
     */
    public function flatten(
        string $parentPrefix = '',
        array $parentMiddleware = [],
        array $parentAttributes = [],
    ): array {
        $result = [];
        $fullPrefix = rtrim($parentPrefix, '/') . '/' . ltrim($this->prefix, '/');
        $fullPrefix = '/' . trim($fullPrefix, '/');
        if ($fullPrefix === '/') {
            $fullPrefix = '';
        }

        $combinedMiddleware = [...$parentMiddleware, ...$this->middleware];
        $combinedAttributes = [...$parentAttributes, ...$this->attributes];

        foreach ($this->routes as $item) {
            if ($item instanceof RouteGroup) {
                $result = [...$result, ...$item->flatten($fullPrefix, $combinedMiddleware, $combinedAttributes)];
            } else {
                // Create new route with applied prefix and middleware
                $routePath = rtrim($fullPrefix, '/') . '/' . ltrim($item->path, '/');
                if ($routePath !== '/') {
                    $routePath = '/' . trim($routePath, '/');
                }

                $result[] = new Route(
                    methods: $item->methods,
                    path: $routePath,
                    handler: $item->handler,
                    name: $item->name,
                    attributes: [...$combinedAttributes, ...$item->attributes],
                    middleware: [...$combinedMiddleware, ...$item->middleware],
                    constraints: $item->constraints,
                    host: $item->host ?? $this->host,
                );
            }
        }

        return $result;
    }
}
