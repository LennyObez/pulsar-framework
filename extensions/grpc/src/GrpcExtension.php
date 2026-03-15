<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc;

use Override;
use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Routing\RouterInterface;

/**
 * gRPC server extension for Pulsar.
 *
 * Provides gRPC service hosting with interceptor pipeline, mTLS,
 * streaming, health checks, and server reflection. Requires a
 * persistent runtime (RoadRunner or FrankenPHP): incompatible with PHP-FPM.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 */
#[Api(since: '1.0.0')]
final readonly class GrpcExtension implements ExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/grpc';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        // All bindings delegated to GrpcServiceProvider.
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // gRPC does not register HTTP routes.
        // The gRPC server runs on its own port via the grpc:serve command.
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [
            GrpcServiceProvider::class,
        ];
    }
}
