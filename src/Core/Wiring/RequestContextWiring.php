<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Context\Middleware\RequestContextMiddleware;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Random\Randomizer;

#[Internal]
final readonly class RequestContextWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $holder = new RequestContextHolder();
        $container->instance(RequestContextHolder::class, $holder);

        /** @var Randomizer $randomizer */
        $randomizer = $container->get(Randomizer::class);

        $contextMiddleware = new RequestContextMiddleware($holder, $randomizer);

        // RequestContext comes after metrics, before error tracker
        $middleware->pipe($contextMiddleware);
    }
}
