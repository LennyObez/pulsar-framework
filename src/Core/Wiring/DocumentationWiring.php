<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\CallableConfigLoader;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\Environment;
use Pulsar\Container\ContainerInterface;
use Pulsar\Documentation\DocumentationConfig;
use Pulsar\Documentation\DocVersionRegistry;
use Pulsar\Documentation\DocVersionResolverMiddleware;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

/**
 * Wires versioned-documentation resolution.
 *
 * Opt-in: when config/documentation.php enables it with at least one version,
 * the {@see DocVersionRegistry} is populated and the
 * {@see DocVersionResolverMiddleware} is piped globally so /docs/{version}/…
 * requests carry the resolved version as a request attribute.
 *
 * Owns config/documentation.php: its loader builds {@see DocumentationConfig}
 * into the ConfigRepository during config load (the single source of truth), so
 * wire() resolves it from the repository and unknown-key reporting is handled
 * once, centrally, by ConfigManager's post-load sweep.
 */
#[Internal]
final readonly class DocumentationWiring implements ServiceWiringInterface, ProvidesConfigLoaders
{
    public function configLoaders(): array
    {
        return [
            'documentation' => new CallableConfigLoader(
                DocumentationConfig::class,
                static fn(array $data, Environment $_environment): object => DocumentationConfig::fromArray($data),
            ),
        ];
    }

    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();
        $config = $repository->has(DocumentationConfig::class)
            ? $repository->get(DocumentationConfig::class)
            : new DocumentationConfig();
        $container->instance(DocumentationConfig::class, $config);

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
}
