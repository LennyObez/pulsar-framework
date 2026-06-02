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

        // Resolve the template engine lazily: ViewWiring binds it AFTER this
        // wiring runs, so capturing it eagerly here would always be null and
        // every error would fall back to the inline page. The closure is a lazy
        // factory from the composition root (not a service locator leaked into
        // the renderer); it is invoked at render time, by which point the engine
        // is bound.
        $templateEngineResolver = static function () use ($container): ?TemplateEngineInterface {
            if (!$container->has(TemplateEngineInterface::class)) {
                return null;
            }

            /** @var TemplateEngineInterface $engine */
            $engine = $container->get(TemplateEngineInterface::class);

            return $engine;
        };

        $renderer = new ErrorPageRenderer(
            devRenderer: $devRenderer,
            debug: $appConfig->debug,
            templateEngineResolver: $templateEngineResolver,
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
