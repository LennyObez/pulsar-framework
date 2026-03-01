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
use Pulsar\Extension\Payments\Contracts\ClockInterface;
use Pulsar\Extension\Payments\Contracts\PaymentGatewayInterface;
use Pulsar\Extension\Payments\Contracts\PaymentProviderInterface;
use Pulsar\Extension\Payments\Contracts\WebhookProcessorInterface;
use Pulsar\Extension\Payments\Features\CreatePaymentIntent\CreatePaymentIntentHandler;
use Pulsar\Extension\Payments\Features\ProcessWebhook\ProcessWebhookHandler;
use Pulsar\Extension\Payments\Features\ProcessWebhook\WebhookController;
use Pulsar\Extension\Payments\Gateway\PaymentGateway;
use Pulsar\Extension\Payments\Internal\Infrastructure\Clock\SystemClock;
use Pulsar\Extension\Payments\Internal\Infrastructure\Provider\NullProvider;
use Pulsar\Extension\Payments\Internal\Infrastructure\Provider\SimulatorProvider;
use Pulsar\Extension\Payments\Internal\Infrastructure\Webhook\HmacWebhookVerifier;
use Pulsar\Extension\Payments\PaymentsServiceProvider;
use Pulsar\Extension\Payments\Webhook\WebhookProcessor;
use Pulsar\Idempotency\IdempotencyStoreInterface;
use Pulsar\Idempotency\InMemoryIdempotencyStore;
use Pulsar\Webhook\InMemoryWebhookEventLog;
use Pulsar\Webhook\WebhookEventLogInterface;
use Pulsar\Webhook\WebhookVerifierInterface;

#[CoversClass(PaymentsServiceProvider::class)]
final class PaymentsServiceProviderTest extends TestCase
{
    #[Test]
    public function providesReturnsAllServiceIds(): void
    {
        $provider = new PaymentsServiceProvider();

        $provides = $provider->provides();

        self::assertContains(ClockInterface::class, $provides);
        self::assertContains(PaymentsConfig::class, $provides);
        self::assertContains(PaymentProviderInterface::class, $provides);
        self::assertContains(IdempotencyStoreInterface::class, $provides);
        self::assertContains(WebhookEventLogInterface::class, $provides);
        self::assertContains(WebhookVerifierInterface::class, $provides);
        self::assertContains(CreatePaymentIntentHandler::class, $provides);
        self::assertContains(ProcessWebhookHandler::class, $provides);
        self::assertContains(PaymentGateway::class, $provides);
        self::assertContains(PaymentGatewayInterface::class, $provides);
        self::assertContains(WebhookProcessor::class, $provides);
        self::assertContains(WebhookProcessorInterface::class, $provides);
        self::assertContains(WebhookController::class, $provides);
    }

    #[Test]
    public function registerBindsClockAsSystemClock(): void
    {
        $container = new Container();
        $provider = new PaymentsServiceProvider();
        $provider->register($container);

        $clock = $container->get(ClockInterface::class);

        self::assertInstanceOf(SystemClock::class, $clock);
    }

    #[Test]
    public function registerBindsPaymentsConfigWithDefaults(): void
    {
        $container = new Container();
        $provider = new PaymentsServiceProvider();
        $provider->register($container);

        /** @var PaymentsConfig $config */
        $config = $container->get(PaymentsConfig::class);

        self::assertSame('null', $config->provider);
        self::assertSame('USD', $config->defaultCurrency);
    }

    #[Test]
    public function registerBindsNullProviderByDefault(): void
    {
        $container = new Container();
        $provider = new PaymentsServiceProvider();
        $provider->register($container);

        $paymentProvider = $container->get(PaymentProviderInterface::class);

        self::assertInstanceOf(NullProvider::class, $paymentProvider);
    }

    #[Test]
    public function registerBindsSimulatorProviderWhenConfigured(): void
    {
        $container = new Container();
        $provider = new PaymentsServiceProvider();
        $provider->register($container);

        // Override the PaymentsConfig to use simulator provider
        $container->forgetInstance(PaymentsConfig::class);
        $simulatorConfig = new PaymentsConfig(
            provider: 'simulator',
            defaultCurrency: 'EUR',
            webhook: new WebhookConfig(
                secret: '',
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
        $container->instance(PaymentsConfig::class, $simulatorConfig);

        $paymentProvider = $container->get(PaymentProviderInterface::class);

        self::assertInstanceOf(SimulatorProvider::class, $paymentProvider);
    }

    #[Test]
    public function registerBindsInMemoryIdempotencyStoreByDefault(): void
    {
        $container = new Container();
        $provider = new PaymentsServiceProvider();
        $provider->register($container);

        $store = $container->get(IdempotencyStoreInterface::class);

        self::assertInstanceOf(InMemoryIdempotencyStore::class, $store);
    }

    #[Test]
    public function registerBindsInMemoryWebhookEventLogByDefault(): void
    {
        $container = new Container();
        $provider = new PaymentsServiceProvider();
        $provider->register($container);

        $log = $container->get(WebhookEventLogInterface::class);

        self::assertInstanceOf(InMemoryWebhookEventLog::class, $log);
    }

    #[Test]
    public function registerBindsHmacWebhookVerifier(): void
    {
        $container = new Container();
        $provider = new PaymentsServiceProvider();
        $provider->register($container);

        $verifier = $container->get(WebhookVerifierInterface::class);

        self::assertInstanceOf(HmacWebhookVerifier::class, $verifier);
    }
}
