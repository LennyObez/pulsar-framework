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
use Pulsar\Observability\ErrorTracking\ErrorAggregator;
use Pulsar\Observability\ErrorTracking\ErrorAggregatorInterface;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Pulsar\Routing\Router;

#[Internal]
final readonly class ErrorTrackingWiring implements ServiceWiringInterface
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

        if (!$observabilityConfig->errorTracking->enabled) {
            return;
        }

        $scrubber = new SensitiveDataScrubber($observabilityConfig->errorTracking->sensitiveFields);
        $aggregator = new ErrorAggregator(
            maxGroups: $observabilityConfig->errorTracking->maxGroups,
            maxRecentEventsPerGroup: $observabilityConfig->errorTracking->maxRecentEventsPerGroup,
            logger: $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
        );

        $container->instance(SensitiveDataScrubber::class, $scrubber);
        $container->instance(ErrorAggregator::class, $aggregator);
        $container->instance(ErrorAggregatorInterface::class, $aggregator);
    }
}
