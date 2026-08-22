<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices;

use Override;
use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Routing\RouterInterface;

/**
 * EU MDR 2017/745 and ISO 13485 medical device compliance extension.
 *
 * Provides UDI management, post-market surveillance, vigilance reporting,
 * clinical investigation tracking, design controls, risk management (ISO 14971),
 * and CAPA (Corrective and Preventive Actions).
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MedicalDevicesExtension implements ExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/medical-devices';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        // All bindings handled by MedicalDevicesServiceProvider
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // No routes registered: this extension provides domain DTOs and services,
        // not HTTP endpoints. Applications wire their own API layer.
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [
            MedicalDevicesServiceProvider::class,
        ];
    }
}
