<?php

declare(strict_types=1);

namespace Pulsar\Container;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use Pulsar\Api\Api;

/**
 * Pulsar container interface extending PSR-11 with binding capabilities.
 */
#[Api]
interface ContainerInterface extends PsrContainerInterface
{
    /**
     * Register a binding in the container.
     *
     * @param string $id The binding identifier (typically an interface or class name)
     * @param callable|class-string $concrete The factory callable or class name
     * @param BindingType $type Whether to resolve as singleton or factory
     */
    public function bind(string $id, callable|string $concrete, BindingType $type = BindingType::Singleton): void;

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
    public function has(string $id): bool;

    /**
     * Resolve a binding from the container.
     *
     * @template T of object
     * @param string|class-string<T> $id The binding identifier
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed;
}
