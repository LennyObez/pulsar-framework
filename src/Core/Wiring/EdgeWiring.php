<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Wiring\Internal\ReportsConfigKeys;
use Pulsar\Edge\EdgeConfig;
use Pulsar\Edge\EdgeFunctionPipeline;
use Pulsar\Edge\EdgeMiddleware;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\TrustedProxy;
use Pulsar\Routing\Router;

use function is_array;
use function is_file;

use const DIRECTORY_SEPARATOR;

/**
 * Wires the edge-function pipeline into the HTTP middleware stack.
 *
 * Opt-in: when config/edge.php enables it with at least one configured function
 * (A/B test or geo redirect), the {@see EdgeMiddleware} is piped so edge logic
 * (redirects, blocks, variant assignment) runs at the front of the request,
 * before routing. Edge functions remain usable as standalone building blocks at
 * a real CDN edge; this just runs the same blocks at the origin.
 */
#[Internal]
final readonly class EdgeWiring implements ServiceWiringInterface
{
    use ReportsConfigKeys;

    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $config = $this->loadConfig($configManager);
        $container->instance(EdgeConfig::class, $config);
        $this->reportUnknownConfigKeys($container, 'edge', $config);

        if (!$config->isUsable()) {
            return;
        }

        $pipeline = new EdgeFunctionPipeline();
        foreach ($config->functions as $function) {
            $pipeline->add($function);
        }
        $container->instance(EdgeFunctionPipeline::class, $pipeline);

        $trustedProxy = $container->has(TrustedProxy::class)
            ? $container->get(TrustedProxy::class)
            : null;

        $edgeMiddleware = new EdgeMiddleware($pipeline, $config->geoCountryHeader, $trustedProxy);
        $container->instance(EdgeMiddleware::class, $edgeMiddleware);

        $middleware->pipe($edgeMiddleware);
    }

    private function loadConfig(ConfigManager $configManager): EdgeConfig
    {
        $configPath = $configManager->configPath();

        if ($configPath !== null && is_file($configPath . DIRECTORY_SEPARATOR . 'edge.php')) {
            /**
             * @psalm-suppress UnresolvableInclude
             * @var mixed $data
             */
            $data = require $configPath . DIRECTORY_SEPARATOR . 'edge.php';

            if (is_array($data)) {
                /** @var array<string, mixed> $data */
                return EdgeConfig::fromArray($data);
            }
        }

        return new EdgeConfig();
    }
}
