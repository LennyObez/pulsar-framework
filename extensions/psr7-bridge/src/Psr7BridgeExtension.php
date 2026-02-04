<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psr7Bridge;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Psr7Bridge\Adapter\Psr7ToPulsarRequest;
use Pulsar\Extension\Psr7Bridge\Adapter\Psr7ToPulsarResponse;
use Pulsar\Extension\Psr7Bridge\Adapter\PulsarToPsr7Request;
use Pulsar\Extension\Psr7Bridge\Adapter\PulsarToPsr7Response;
use Pulsar\Routing\RouterInterface;

/**
 * PSR-7/PSR-15 bridge extension for Pulsar.
 *
 * Provides bidirectional conversion between Pulsar HTTP objects and PSR-7
 * interfaces, plus a PSR-15 middleware adapter for using PSR-15 middleware
 * in the Pulsar pipeline.
 */
final class Psr7BridgeExtension implements ExtensionInterface
{
    public function name(): string
    {
        return 'pulsar/psr7-bridge';
    }

    public function register(ContainerInterface $container): void
    {
        $container->bind(
            PulsarToPsr7Request::class,
            PulsarToPsr7Request::class,
        );

        $container->bind(
            Psr7ToPulsarRequest::class,
            Psr7ToPulsarRequest::class,
        );

        $container->bind(
            PulsarToPsr7Response::class,
            PulsarToPsr7Response::class,
        );

        $container->bind(
            Psr7ToPulsarResponse::class,
            Psr7ToPulsarResponse::class,
        );
    }

    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // No routes or boot-time setup required for the bridge
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function providers(): array
    {
        return [];
    }
}
