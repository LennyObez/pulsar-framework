<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form;

use Override;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Routing\RouterInterface;

/**
 * Form builder extension.
 *
 * Provides form construction, CSRF protection, validation binding,
 * regulated consent fields, file upload handling, and multi-step wizards.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 */
final readonly class FormExtension implements ExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/form';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        // Service provider handles all bindings
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // Form extension does not register routes
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [
            FormServiceProvider::class,
        ];
    }
}
