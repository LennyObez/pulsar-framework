<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use function array_key_exists;
use function is_string;

use Pulsar\Api\Internal;
use RuntimeException;

use function sprintf;

/**
 * Registry for named middleware groups and aliases.
 *
 * Groups map a single name to an ordered list of middleware.
 * Aliases map a short name to a single middleware class-string or instance.
 */
#[Internal]
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
     * Recursively resolves aliases and group members. Detects circular
     * references and throws RuntimeException if found.
     *
     * Resolution order: alias → group → return as-is (class-string).
     *
     * @return list<MiddlewareInterface|class-string<MiddlewareInterface>>
     *
     * @throws RuntimeException If a circular middleware reference is detected
     */
    public function resolve(string $name): array
    {
        $stack = [];

        return $this->doResolve($name, $stack);
    }

    /**
     * @param array<string, true> $stack In-progress resolution stack for cycle detection
     *
     * @return list<MiddlewareInterface|class-string<MiddlewareInterface>>
     *
     * @throws RuntimeException If a circular middleware reference is detected
     */
    private function doResolve(string $name, array &$stack): array
    {
        if (isset($stack[$name])) {
            throw new RuntimeException(sprintf(
                'Circular middleware reference detected: "%s"',
                $name,
            ));
        }

        $stack[$name] = true;

        try {
            if (array_key_exists($name, $this->aliases)) {
                $aliasTarget = $this->aliases[$name];

                if (is_string($aliasTarget) && ($this->hasAlias($aliasTarget) || $this->hasGroup($aliasTarget))) {
                    return $this->doResolve($aliasTarget, $stack);
                }

                return [$aliasTarget];
            }

            if (array_key_exists($name, $this->groups)) {
                $result = [];

                foreach ($this->groups[$name] as $item) {
                    if (is_string($item) && ($this->hasAlias($item) || $this->hasGroup($item))) {
                        $result = [...$result, ...$this->doResolve($item, $stack)];
                    } else {
                        $result[] = $item;
                    }
                }

                return $result;
            }

            // Assume it's a class-string and return as-is
            /** @var class-string<MiddlewareInterface> $name */
            return [$name];
        } finally {
            unset($stack[$name]);
        }
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
