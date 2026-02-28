<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\ErrorHandling\DevelopmentRenderer;
use Pulsar\ErrorHandling\ErrorPageRenderer;
use Pulsar\ErrorHandling\ExceptionHandler;
use Pulsar\ErrorHandling\ExceptionRendererInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\ErrorTracking\ErrorAggregator;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Pulsar\Routing\Router;
use Pulsar\View\Engine\TemplateEngineInterface;

#[Internal]
final readonly class ExceptionHandlerWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        /** @var AppConfig $appConfig */
        $appConfig = $configManager->repository()->get(AppConfig::class);

        $scrubber = $container->has(SensitiveDataScrubber::class)
            ? $container->get(SensitiveDataScrubber::class)
            : null;

        /** @var SensitiveDataScrubber|null $scrubber */
        $devRenderer = new DevelopmentRenderer($scrubber ?? new SensitiveDataScrubber());

        $templateEngine = $container->has(TemplateEngineInterface::class)
            ? $container->get(TemplateEngineInterface::class)
            : null;

        /** @var TemplateEngineInterface|null $templateEngine */
        $renderer = new ErrorPageRenderer(
            templateEngine: $templateEngine,
            devRenderer: $devRenderer,
            debug: $appConfig->debug,
        );
        $container->instance(ExceptionRendererInterface::class, $renderer);

        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : null;

        $aggregator = $container->has(ErrorAggregator::class)
            ? $container->get(ErrorAggregator::class)
            : null;

        /** @var LoggerInterface|null $logger */
        /** @var ErrorAggregator|null $aggregator */
        /** @var SensitiveDataScrubber|null $scrubber */
        $exceptionHandler = new ExceptionHandler($renderer, $logger, $aggregator, $scrubber);
        $container->instance(ExceptionHandler::class, $exceptionHandler);
    }
}
