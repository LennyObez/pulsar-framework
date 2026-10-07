<?php

declare(strict_types=1);

namespace Pulsar\Core\Controller;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\Exception\ContainerException;
use Pulsar\Container\Exception\NotFoundException;
use Pulsar\Routing\RoutingException;
use ReflectionClass;

use function class_exists;

/**
 * Default controller resolver: delegates to the container's autowiring.
 *
 * A bound controller resolves through the container (so in production it
 * benefits from compiled resolution hints and its configured lifetime). An
 * unbound but instantiable controller is autowired by the container, which
 * pulls each constructor dependency — autowiring plain concretes and resolving
 * bound interfaces. The container is the single autowiring authority; this
 * resolver only adds routing-specific guards (missing / non-instantiable class)
 * and surfaces the controller name alongside any unresolved-dependency detail,
 * so the failing edge is obvious instead of an opaque "no binding found".
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

        if (!class_exists($class)) {
            throw RoutingException::invalidHandler($class . ': class does not exist');
        }

        if (!new ReflectionClass($class)->isInstantiable()) {
            throw RoutingException::invalidHandler(
                $class . ' is not instantiable (abstract class or interface)',
            );
        }

        try {
            /** @var object */
            return $this->container->get($class);
        } catch (NotFoundException | ContainerException $e) {
            // The container names the unresolved dependency + parameter; prefix
            // the controller so the whole edge is visible in the 500/log.
            throw RoutingException::invalidHandler($class . ': ' . $e->getMessage());
        }
    }
}
