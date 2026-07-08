<?php

declare(strict_types=1);

namespace Pulsar\Core\Boot;

use Closure;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Routing\RouteCollision;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;

use function get_debug_type;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Reports route collisions discovered during boot.
 *
 * Route registration is first-registered-wins (framework > project > extension),
 * so a later route claiming an already-registered (method, path, host) key is a
 * shadowed collision (see {@see Router::$collisions}). This reporter logs a
 * warning for each collision in production and fails closed (throws) in debug
 * mode, turning a silent wrong-page bug — an extension route shadowing a project
 * route — into a visible, actionable signal.
 *
 * @internal Boot-time only, invoked by {@see \Pulsar\Core\Kernel} after every
 *           route-registering step has run.
 */
#[Internal]
final class RouteCollisionReporter
{
    /**
     * @throws RoutingException When $debug is true and at least one collision exists.
     */
    public static function report(Router $router, ContainerInterface $container, bool $debug): void
    {
        if ($router->collisions === []) {
            return;
        }

        /** @var LoggerInterface|null $logger */
        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : null;

        $messages = [];

        foreach ($router->collisions as $collision) {
            $message = self::describe($collision);
            $messages[] = $message;

            $logger?->warning($message, [
                'method' => $collision->method,
                'path' => $collision->path,
                'host' => $collision->host,
            ]);
        }

        if ($debug) {
            throw RoutingException::routeCollisions($messages);
        }
    }

    private static function describe(RouteCollision $collision): string
    {
        $host = $collision->host !== null ? ' host=' . $collision->host : '';

        return sprintf(
            'Route collision: %s %s%s is registered by two routes. First-registered wins '
            . '(framework > project > extension); the shadowed handler %s will never match. '
            . 'Give the later route a distinct prefix or disable it.',
            $collision->method,
            $collision->path,
            $host,
            self::describeHandler($collision->shadowed->handler),
        );
    }

    private static function describeHandler(mixed $handler): string
    {
        if (is_string($handler)) {
            return $handler;
        }

        if ($handler instanceof Closure) {
            return 'Closure';
        }

        if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[1])) {
            $class = is_string($handler[0]) ? $handler[0] : get_debug_type($handler[0]);

            return $class . '::' . $handler[1];
        }

        return get_debug_type($handler);
    }
}
