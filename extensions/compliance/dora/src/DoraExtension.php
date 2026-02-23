<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora;

use Override;
use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Routing\RouterInterface;

/**
 * EU DORA (2022/2554) Digital Operational Resilience Act extension.
 *
 * Provides ICT risk management, incident management, digital operational
 * resilience testing, third-party ICT risk management, and cyber threat
 * information sharing capabilities for financial entities.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 */
#[Api(since: '1.0.0')]
final readonly class DoraExtension implements ExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/dora';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        // All bindings handled by DoraServiceProvider
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // No routes: DORA provides domain DTOs and services
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [
            DoraServiceProvider::class,
        ];
    }
}
