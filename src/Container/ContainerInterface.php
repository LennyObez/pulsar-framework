<?php

declare(strict_types=1);

namespace Pulsar\Container;

use NoDiscard;
use Override;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Pulsar\Api\Api;

/**
 * Pulsar container interface extending PSR-11 with binding capabilities.
 * @api
 */
#[Api(since: '1.0.0')]
interface ContainerInterface extends PsrContainerInterface
{
    /**
     * Register a binding in the container.
     *
     * For request-scoped or tenant-scoped lifetimes, use
     * {@see AdvancedContainerInterface::bindWithLifetime()} instead.
     *
     * Factory closures must not call {@see \Fiber::suspend()}: the container
     * resolves bindings synchronously and suspension would leave the container
     * in an inconsistent state. See ADR-0005.
     *
     * @param string $id The binding identifier (typically an interface or class name)
     * @param callable|class-string $concrete The factory callable or class name
     * @param BindingType $type Whether to resolve as singleton or factory
     */
    public function bind(string $id, callable|string $concrete, BindingType $type = BindingType::Singleton): void;

    /**
     * Register a singleton binding (convenience alias for bind with Singleton type).
     *
     * @param string $id The binding identifier
     * @param callable|class-string $concrete The factory callable or class name
     */
    public function singleton(string $id, callable|string $concrete): void;

    /**
     * Register an existing instance in the container.
     *
     * The instance is always treated as a singleton.
     *
     * @param string $id The binding identifier
     * @param object $instance The instance to register
     */
    public function instance(string $id, object $instance): void;

    /**
     * Check if a binding or instance exists for the given identifier.
     *
     * @param string $id The binding identifier
     */
    #[Override]
    public function has(string $id): bool;

    /**
     * Resolve a binding from the container.
     *
     * @template T of object
     * @param string|class-string<T> $id The binding identifier
     * @return ($id is class-string<T> ? T : mixed)
     */
    #[Override]
    #[NoDiscard]
    public function get(string $id): mixed;

    /**
     * Remove a cached singleton instance, forcing re-resolution on next get().
     *
     * Used by the persistent runtime to evict request-bound services
     * between requests. Does nothing if the ID has no cached instance.
     *
     * @param string $id The binding identifier to evict
     */
    public function forgetInstance(string $id): void;

    /**
     * Set pre-computed constructor resolution hints for autowiring.
     *
     * @param array<class-string, list<array{name: string, type: class-string}>>|null $hints
     */
    public function setResolutionHints(?array $hints): void;

    /**
     * Get all registered binding IDs.
     *
     * Used by framework tooling (optimize, diagnostics) for container introspection.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function getBindings(): array;

    /**
     * Get all cached instance IDs.
     *
     * Used by framework tooling (diagnostics) for container introspection.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function getInstances(): array;

    /**
     * Call a callable, resolving type-hinted parameters from the container.
     *
     * Explicit parameters in $params take precedence over container resolution.
     * Parameters are matched by name first, then by type.
     *
     * @param callable $callable The callable to invoke
     * @param array<string, mixed> $params Explicit parameter overrides keyed by name
     *
     * @return mixed The callable's return value
     */
    public function call(callable $callable, array $params = []): mixed;
}
