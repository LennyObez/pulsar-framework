<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Routing\RouterInterface;

/**
 * Object-relational mapping extension.
 *
 * Provides entity mapping, query building, schema management,
 * encrypted columns, tenant scoping, and audit-trail persistence.
 */
final readonly class OrmExtension implements ExtensionInterface
{
    public function name(): string
    {
        return 'pulsar/orm';
    }

    public function register(ContainerInterface $container): void
    {
        // Service provider handles all bindings
    }

    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // ORM does not register routes
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function providers(): array
    {
        return [
            OrmServiceProvider::class,
        ];
    }
}
