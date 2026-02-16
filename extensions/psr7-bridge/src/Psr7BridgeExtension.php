<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psr7Bridge;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Routing\RouterInterface;

/**
 * PSR-7/PSR-15 bridge extension for Pulsar.
 *
 * Provides bidirectional conversion between Pulsar HTTP objects and PSR-7
 * interfaces, plus a PSR-15 middleware adapter for using PSR-15 middleware
 * in the Pulsar pipeline.
 *
 * @deprecated Since 1.0.0-rc.11. Pulsar now uses PSR-7/PSR-15 natively.
 *             This entire extension is no longer needed.
 */
final class Psr7BridgeExtension implements ExtensionInterface
{
    public function name(): string
    {
        return 'pulsar/psr7-bridge';
    }

    public function register(ContainerInterface $container): void
    {
        // No-op: Pulsar uses PSR-7 natively since v1.0.0-rc.11.
        // The bridge adapters are deprecated and will be removed in v2.0.
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
