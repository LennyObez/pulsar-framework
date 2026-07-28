<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Wiring\Internal\ReportsConfigKeys;
use Pulsar\Documentation\DocumentationConfig;
use Pulsar\Documentation\DocVersionRegistry;
use Pulsar\Documentation\DocVersionResolverMiddleware;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

use function is_array;
use function is_file;

use const DIRECTORY_SEPARATOR;

/**
 * Wires versioned-documentation resolution.
 *
 * Opt-in: when config/documentation.php enables it with at least one version,
 * the {@see DocVersionRegistry} is populated and the
 * {@see DocVersionResolverMiddleware} is piped globally so /docs/{version}/…
 * requests carry the resolved version as a request attribute.
 */
#[Internal]
final readonly class DocumentationWiring implements ServiceWiringInterface
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
        $container->instance(DocumentationConfig::class, $config);
        $this->reportUnknownConfigKeys($container, 'documentation', $config);

        if (!$config->isUsable()) {
            return;
        }

        $registry = new DocVersionRegistry();
        foreach ($config->versions as $version) {
            $registry->register($version);
        }
        $container->instance(DocVersionRegistry::class, $registry);

        $resolver = new DocVersionResolverMiddleware($registry);
        $container->instance(DocVersionResolverMiddleware::class, $resolver);

        $middleware->pipe($resolver);
    }

    private function loadConfig(ConfigManager $configManager): DocumentationConfig
    {
        $configPath = $configManager->configPath();

        if ($configPath !== null && is_file($configPath . DIRECTORY_SEPARATOR . 'documentation.php')) {
            /**
             * @psalm-suppress UnresolvableInclude
             * @var mixed $data
             */
            $data = require $configPath . DIRECTORY_SEPARATOR . 'documentation.php';

            if (is_array($data)) {
                /** @var array<string, mixed> $data */
                return DocumentationConfig::fromArray($data);
            }
        }

        return new DocumentationConfig();
    }
}
