<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\Log\Logger;
use Pulsar\Observability\Log\Sink\DeferredSink;
use Pulsar\Observability\Log\Sink\DeferredSinkInterface;
use Pulsar\Routing\Router;

#[Internal]
final readonly class LoggingWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        /** @var ObservabilityConfig $observabilityConfig */
        $observabilityConfig = $configManager->repository()->get(ObservabilityConfig::class);

        $deferredSink = new DeferredSink();
        $container->instance(DeferredSink::class, $deferredSink);
        $container->instance(DeferredSinkInterface::class, $deferredSink);
        $logger = Logger::fromConfigWithExtraSinks($observabilityConfig, [$deferredSink]);

        $container->instance(LoggerInterface::class, $logger);
        $container->instance(Logger::class, $logger);
    }
}
