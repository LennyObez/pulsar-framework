<?php

declare(strict_types=1);

namespace Pulsar\Container;

use function in_array;
use function is_callable;
use function is_object;

use Override;
use Pulsar\Container\Exception\ContainerException;
use Pulsar\Container\Exception\NotFoundException;
use ReflectionClass;
use ReflectionNamedType;

use function sprintf;

/**
 * Array-based dependency injection container.
 *
 * Implements PSR-11 and provides singleton/factory binding support.
 */
final class Container implements ContainerInterface
{
    /**
     * Registered bindings.
     *
     * @var array<string, array{concrete: callable|class-string, type: BindingType}>
     */
    private array $bindings = [];

    /**
     * Resolved singleton instances.
     *
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * IDs currently being resolved (for circular dependency detection).
     *
     * @var list<string>
     */
    private array $resolving = [];

    #[Override]
    public function bind(string $id, callable|string $concrete, BindingType $type = BindingType::Singleton): void
    {
        $this->bindings[$id] = [
            'concrete' => $concrete,
            'type' => $type,
        ];

        // Clear any cached instance if rebinding
        unset($this->instances[$id]);
    }

    #[Override]
    public function instance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    #[Override]
    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) || isset($this->instances[$id]);
    }

    #[Override]
    public function get(string $id): mixed
    {
        // Return cached instance if available
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // Check for binding
        if (!isset($this->bindings[$id])) {
            throw NotFoundException::forId($id);
        }

        return $this->resolve($id);
    }

    /**
     * Resolve a binding to its concrete implementation.
     */
    private function resolve(string $id): object
    {
        // Circular dependency detection
        if (in_array($id, $this->resolving, true)) {
            throw ContainerException::circularDependency($id, $this->resolving);
        }

        $this->resolving[] = $id;

        try {
            $binding = $this->bindings[$id];
            $concrete = $binding['concrete'];

            // Resolve the concrete implementation
            $instance = is_callable($concrete)
                ? $concrete($this)
                : $this->build($concrete);

            if (!is_object($instance)) {
                throw ContainerException::unresolvable(
                    $id,
                    'Factory must return an object',
                );
            }

            // Cache singleton instances
            if ($binding['type'] === BindingType::Singleton) {
                $this->instances[$id] = $instance;
            }

            return $instance;
        } finally {
            array_pop($this->resolving);
        }
    }

    /**
     * Build a class instance using autowiring.
     *
     * @param class-string $className
     */
    private function build(string $className): object
    {
        if (!class_exists($className)) {
            throw ContainerException::unresolvable(
                $className,
                sprintf('Class "%s" does not exist', $className),
            );
        }

        $reflector = new ReflectionClass($className);

        if (!$reflector->isInstantiable()) {
            throw ContainerException::unresolvable(
                $className,
                sprintf('Class "%s" is not instantiable', $className),
            );
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $className();
        }

        $parameters = $constructor->getParameters();
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if ($type === null) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                    continue;
                }

                throw ContainerException::unresolvable(
                    $className,
                    sprintf(
                        'Parameter "%s" has no type hint and no default value',
                        $parameter->getName(),
                    ),
                );
            }

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                    continue;
                }

                throw ContainerException::unresolvable(
                    $className,
                    sprintf(
                        'Parameter "%s" requires a non-class type "%s"',
                        $parameter->getName(),
                        $type instanceof ReflectionNamedType ? $type->getName() : 'unknown',
                    ),
                );
            }

            $dependencyClass = $type->getName();

            try {
                $dependencies[] = $this->get($dependencyClass);
            } catch (NotFoundException) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } elseif ($type->allowsNull()) {
                    $dependencies[] = null;
                } else {
                    throw ContainerException::unresolvable(
                        $className,
                        sprintf(
                            'Unable to resolve dependency "%s" for parameter "%s"',
                            $dependencyClass,
                            $parameter->getName(),
                        ),
                    );
                }
            }
        }

        return new $className(...$dependencies);
    }

    /**
     * Get all registered binding IDs.
     *
     * @return list<string>
     */
    public function getBindings(): array
    {
        /** @var list<string> */
        return array_keys($this->bindings);
    }

    /**
     * Get all cached instance IDs.
     *
     * @return list<string>
     */
    public function getInstances(): array
    {
        /** @var list<string> */
        return array_keys($this->instances);
    }
}
