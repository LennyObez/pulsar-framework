<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use function array_key_exists;

/**
 * Registry for named middleware groups and aliases.
 *
 * Groups map a single name to an ordered list of middleware.
 * Aliases map a short name to a single middleware class-string or instance.
 */
final class MiddlewareRegistry
{
    /** @var array<string, list<MiddlewareInterface|class-string<MiddlewareInterface>>> */
    private array $groups = [];

    /** @var array<string, MiddlewareInterface|class-string<MiddlewareInterface>> */
    private array $aliases = [];

    /**
     * Register a named middleware group.
     *
     * @param list<MiddlewareInterface|class-string<MiddlewareInterface>> $middleware
     */
    public function group(string $name, array $middleware): self
    {
        $this->groups[$name] = $middleware;
        return $this;
    }

    /**
     * Register a middleware alias (short name for a single middleware).
     *
     * @param MiddlewareInterface|class-string<MiddlewareInterface> $middleware
     */
    public function alias(string $name, MiddlewareInterface|string $middleware): self
    {
        $this->aliases[$name] = $middleware;
        return $this;
    }

    /**
     * Resolve a middleware name to a list of middleware.
     *
     * Resolution order: alias → group → return as-is (class-string).
     *
     * @return list<MiddlewareInterface|class-string<MiddlewareInterface>>
     */
    public function resolve(string $name): array
    {
        if (array_key_exists($name, $this->aliases)) {
            return [$this->aliases[$name]];
        }

        if (array_key_exists($name, $this->groups)) {
            return $this->groups[$name];
        }

        // Assume it's a class-string and return as-is
        /** @var class-string<MiddlewareInterface> $name */
        return [$name];
    }

    public function hasGroup(string $name): bool
    {
        return array_key_exists($name, $this->groups);
    }

    public function hasAlias(string $name): bool
    {
        return array_key_exists($name, $this->aliases);
    }
}
