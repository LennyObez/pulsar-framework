<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2;

use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Routing\RouterInterface;

/**
 * PSD2 compliance extension.
 *
 * Provides Strong Customer Authentication (SCA) dynamic linking,
 * transaction risk monitoring, and open banking certificate validation
 * per PSD2 (Payment Services Directive 2) requirements.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 */
#[Api(since: '1.0.0')]
final class Psd2Extension implements ExtensionInterface
{
    public function name(): string
    {
        return 'pulsar/psd2';
    }

    public function register(ContainerInterface $container): void
    {
        // Service provider handles all bindings
    }

    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // No routes registered by default; applications wire their own
        // PSD2 routes using the provided middleware and services
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function providers(): array
    {
        return [
            Psd2ServiceProvider::class,
        ];
    }
}
