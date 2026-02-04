<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Features\ProcessWebhook\WebhookController;
use Pulsar\Routing\RouterInterface;

/**
 * Vendor-agnostic payments extension.
 *
 * Provides payment processing, webhook verification, and idempotency
 * enforcement without external SDK dependencies.
 */
final class PaymentsExtension implements ExtensionInterface
{
    public function name(): string
    {
        return 'pulsar/payments';
    }

    public function register(ContainerInterface $container): void
    {
        // Service provider handles all bindings
    }

    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        /** @var PaymentsConfig $config */
        $config = $container->get(PaymentsConfig::class);

        $router->post(
            $config->webhook->path,
            [WebhookController::class, 'handle'],
            'payments.webhook',
        );
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function providers(): array
    {
        return [
            PaymentsServiceProvider::class,
        ];
    }
}
