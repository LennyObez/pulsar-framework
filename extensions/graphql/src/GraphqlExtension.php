<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql;

use Override;
use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Graphql\Http\GraphqlController;
use Pulsar\Routing\RouterInterface;

/**
 * GraphQL API extension for Pulsar CMS.
 *
 * Provides a read-only GraphQL endpoint for querying content,
 * taxonomies, and media assets.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 */
#[Api(since: '1.0.0')]
final readonly class GraphqlExtension implements ExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/graphql';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        // Service provider handles all bindings.
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        if (!$container->has(GraphqlController::class)) {
            return;
        }

        $router->post('/graphql', [GraphqlController::class, 'execute'], 'graphql.execute');
        $router->get('/graphql', [GraphqlController::class, 'introspect'], 'graphql.introspect');
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [
            GraphqlServiceProvider::class,
        ];
    }
}
