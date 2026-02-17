<?php

declare(strict_types=1);

namespace Pulsar\Live;

use Pulsar\Api\Api;

use function count;

/**
 * Registry for live components.
 *
 * Maps component names to their class names for lookup during requests.
 */
#[Api(since: '1.0.0')]
final class LiveComponentRegistry
{
    /** @var array<string, class-string<LiveComponent>> */
    private array $components = [];

    /**
     * Register a component class.
     *
     * @param class-string<LiveComponent> $className
     */
    public function register(string $name, string $className): void
    {
        $this->components[$name] = $className;
    }

    /**
     * Resolve a component class by name.
     *
     * @return class-string<LiveComponent>|null
     */
    public function resolve(string $name): ?string
    {
        return $this->components[$name] ?? null;
    }

    /**
     * Whether a component is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->components[$name]);
    }

    /**
     * Get all registered component names.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->components);
    }

    /**
     * Get the total number of registered components.
     */
    public function count(): int
    {
        return count($this->components);
    }
}
