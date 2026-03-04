<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa;

use Override;
use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Routing\RouterInterface;

/**
 * EU Digital Services Act (Regulation 2022/2065) compliance extension.
 *
 * Provides content moderation logging, transparency reporting,
 * trusted flagger management, notice-and-action mechanisms, and
 * internal complaint handling as required by the DSA for online
 * intermediary services, hosting services, platforms, and VLOPs.
 */
#[Api(since: '1.0.0')]
final readonly class DsaExtension implements ExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/dsa';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        // All bindings handled by DsaServiceProvider
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // No routes: applications wire DSA endpoints as needed
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [
            DsaServiceProvider::class,
        ];
    }
}
