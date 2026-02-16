<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Extension\Payments\Config\BancontactConfig;
use Pulsar\Extension\Payments\Config\IdealConfig;
use Pulsar\Extension\Payments\Config\IdempotencyConfig;
use Pulsar\Extension\Payments\Config\KlarnaConfig;
use Pulsar\Extension\Payments\Config\MobileConfig;
use Pulsar\Extension\Payments\Config\PayconiqConfig;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Config\PayPalConfig;
use Pulsar\Extension\Payments\Config\SepaConfig;
use Pulsar\Extension\Payments\Config\StripeConfig;
use Pulsar\Extension\Payments\Config\WebhookConfig;
use Pulsar\Extension\Payments\Config\WebhookLogConfig;
use Pulsar\Extension\Payments\PaymentsExtension;
use Pulsar\Extension\Payments\PaymentsServiceProvider;
use Pulsar\Routing\Router;

#[CoversClass(PaymentsExtension::class)]
final class PaymentsExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarPayments(): void
    {
        $extension = new PaymentsExtension();

        self::assertSame('pulsar/payments', $extension->name());
    }

    #[Test]
    public function registerIsNoOp(): void
    {
        $extension = new PaymentsExtension();
        $container = new Container();

        $extension->register($container);

        // register() is intentionally empty — service provider handles bindings
        // Verify no bindings were added (container has no extra state)
        self::assertSame([], $container->getBindings());
    }

    #[Test]
    public function providersReturnsServiceProviderClass(): void
    {
        $extension = new PaymentsExtension();

        $providers = $extension->providers();

        self::assertSame([PaymentsServiceProvider::class], $providers);
    }

    #[Test]
    public function bootRegistersWebhookRoute(): void
    {
        $extension = new PaymentsExtension();
        $container = new Container();
        $router = new Router();

        $config = new PaymentsConfig(
            provider: 'null',
            defaultCurrency: 'USD',
            webhook: new WebhookConfig(
                secret: 'test-secret',
                path: '/webhooks/payments',
                toleranceSeconds: 300,
                signatureHeader: 'X-Payments-Signature',
            ),
            idempotency: new IdempotencyConfig(
                ttlSeconds: 86400,
                store: 'memory',
                maxKeyLength: 256,
            ),
            webhookLog: new WebhookLogConfig(
                ttlSeconds: 259200,
                store: 'memory',
            ),
            stripe: new StripeConfig(
                secretKey: '',
                publishableKey: '',
                webhookSecret: '',
                apiVersion: '2024-12-18.acacia',
                testMode: true,
            ),
            paypal: new PayPalConfig(
                clientId: '',
                clientSecret: '',
                webhookId: '',
                sandbox: true,
            ),
            sepa: SepaConfig::fromArray([]),
            mobile: MobileConfig::fromArray([]),
            payconiq: PayconiqConfig::fromArray([]),
            bancontact: BancontactConfig::fromArray([]),
            ideal: IdealConfig::fromArray([]),
            klarna: KlarnaConfig::fromArray([]),
            subscriptionsEnabled: false,
            invoiceRetentionDays: 3650,
            dunningMaxRetries: 4,
            trialMaxDays: 30,
        );

        $container->instance(PaymentsConfig::class, $config);

        $extension->boot($container, $router);

        $found = false;

        foreach ($router->routes as $route) {
            if ($route->name === 'payments.webhook') {
                $found = true;
                self::assertSame('/webhooks/payments', $route->path);

                break;
            }
        }

        self::assertTrue($found, 'Webhook route was not registered');
    }
}
