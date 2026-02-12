<?php

declare(strict_types=1);

namespace Pulsar\Container\Lazy;

use Closure;
use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;

use function is_callable;
use function is_string;

/**
 * Creates native PHP lazy proxy objects for deferred service instantiation.
 *
 * Uses ReflectionClass::newLazyProxy() (PHP 8.4+) to create a proxy that
 * defers actual service construction until first property/method access.
 */
#[Internal]
final class LazyServiceFactory
{
    /**
     * Create a lazy proxy for the given service.
     *
     * @param string $id Service identifier
     * @param callable|class-string $concrete Factory callable or class name
     * @param ContainerInterface $container Container for dependency resolution
     * @return object Lazy proxy that resolves on first access
     */
    #[NoDiscard]
    public static function create(string $id, callable|string $concrete, ContainerInterface $container): object
    {
        // Determine the class to proxy
        if (is_string($concrete) && !is_callable($concrete)) {
            /** @var class-string $className */
            $className = $concrete;
        } else {
            // For callable factories, we need to resolve once to get the class,
            // then create a proxy of that class. Since we can't know the class
            // without calling the factory, we call it lazily via the proxy.
            // Use the binding ID as the class if it's a valid class.
            if (class_exists($id)) {
                /** @var class-string $className */
                $className = $id;
            } else {
                // Cannot create a lazy proxy without a known class — resolve eagerly
                /** @var object */
                return $concrete($container);
            }
        }

        $reflector = new ReflectionClass($className);

        return $reflector->newLazyProxy(static function () use ($concrete, $container): object {
            if ($concrete instanceof Closure || (is_string($concrete) && is_callable($concrete))) {
                /** @var object */
                return $concrete($container);
            }

            // Class-string path — resolve via container autowiring
            /** @var class-string $concrete */
            if (!class_exists($concrete)) {
                throw new RuntimeException("Class {$concrete} does not exist");
            }

            /** @var object */
            return $container->get($concrete);
        });
    }
}
