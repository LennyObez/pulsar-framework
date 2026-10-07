<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas;

use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Routing\RouterInterface;

/**
 * eIDAS compliance extension.
 *
 * Provides electronic signatures, seals, qualified timestamps,
 * registered delivery, and assurance level enforcement per
 * eIDAS Regulation (EU No 910/2014).
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 * @api
 */
#[Api(since: '1.0.0')]
final class EidasExtension implements ExtensionInterface
{
    public function name(): string
    {
        return 'pulsar/eidas';
    }

    public function register(ContainerInterface $container): void
    {
        // Service provider handles all bindings
    }

    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // No routes registered by default; applications wire LoA
        // middleware on routes requiring specific assurance levels
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function providers(): array
    {
        return [
            EidasServiceProvider::class,
        ];
    }
}
