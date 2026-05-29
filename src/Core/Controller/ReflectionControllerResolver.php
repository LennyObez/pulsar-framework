<?php

declare(strict_types=1);

namespace Pulsar\Core\Controller;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Routing\RoutingException;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;

use function class_exists;

/**
 * Default controller resolver: container-first, reflection-autowired fallback.
 *
 * A controller bound in the container is resolved through it (so in production
 * it benefits from compiled resolution hints). An unbound controller is
 * autowired by reflecting its constructor and pulling each class-typed
 * dependency from the container, falling back to default/nullable values.
 * This is the reflection path that previously lived inline in the Kernel; it
 * is isolated here so it can be unit-tested and replaced wholesale.
 */
#[Internal(reason: 'Default controller resolution strategy; depend on ControllerResolverInterface')]
final class ReflectionControllerResolver implements ControllerResolverInterface
{
    public function __construct(private readonly ContainerInterface $container) {}

    #[Override]
    public function resolve(string $class): object
    {
        if ($this->container->has($class)) {
            /** @var object */
            return $this->container->get($class);
        }

        // Autowire: resolve constructor dependencies from the container.
        try {
            if (!class_exists($class)) {
                throw RoutingException::invalidHandler($class . ': class does not exist');
            }

            $reflection = new ReflectionClass($class);

            if (!$reflection->isInstantiable()) {
                throw RoutingException::invalidHandler(
                    $class . ' is not instantiable (abstract class or interface)',
                );
            }

            $constructor = $reflection->getConstructor();

            if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
                return $reflection->newInstance();
            }

            $args = [];

            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();

                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    $typeName = $type->getName();

                    if ($this->container->has($typeName)) {
                        $args = [...$args, $this->container->get($typeName)];

                        continue;
                    }
                }

                if ($param->isDefaultValueAvailable()) {
                    $args = [...$args, $param->getDefaultValue()];

                    continue;
                }

                if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
                    $args = [...$args, null];

                    continue;
                }

                throw RoutingException::invalidHandler(
                    $class . ': cannot resolve constructor parameter $' . $param->getName(),
                );
            }

            return $reflection->newInstanceArgs($args);
        } catch (RoutingException $e) {
            throw $e;
        } catch (ReflectionException) {
            throw RoutingException::invalidHandler(
                $class . ' (not registered in the container: required dependencies are unavailable)',
            );
        }
    }
}
