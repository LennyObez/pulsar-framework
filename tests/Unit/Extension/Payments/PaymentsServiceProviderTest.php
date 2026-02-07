<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
<<<<<<< feat/modular-monolith-payments
=======
use Pulsar\Extension\Payments\Clock\SystemClock;
>>>>>>> main
use Pulsar\Extension\Payments\Config\IdempotencyConfig;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Config\WebhookConfig;
use Pulsar\Extension\Payments\Config\WebhookLogConfig;
<<<<<<< feat/modular-monolith-payments
use Pulsar\Extension\Payments\Contracts\ClockInterface;
use Pulsar\Extension\Payments\Contracts\IdempotencyStoreInterface;
use Pulsar\Extension\Payments\Contracts\PaymentGatewayInterface;
use Pulsar\Extension\Payments\Contracts\PaymentProviderInterface;
use Pulsar\Extension\Payments\Contracts\WebhookEventLogInterface;
use Pulsar\Extension\Payments\Contracts\WebhookProcessorInterface;
use Pulsar\Extension\Payments\Contracts\WebhookVerifierInterface;
use Pulsar\Extension\Payments\Features\CreatePaymentIntent\CreatePaymentIntentHandler;
use Pulsar\Extension\Payments\Features\ProcessWebhook\ProcessWebhookHandler;
use Pulsar\Extension\Payments\Features\ProcessWebhook\WebhookController;
use Pulsar\Extension\Payments\Gateway\PaymentGateway;
use Pulsar\Extension\Payments\Internal\Infrastructure\Clock\SystemClock;
use Pulsar\Extension\Payments\Internal\Infrastructure\Idempotency\InMemoryIdempotencyStore;
use Pulsar\Extension\Payments\Internal\Infrastructure\Provider\NullProvider;
use Pulsar\Extension\Payments\Internal\Infrastructure\Provider\SimulatorProvider;
use Pulsar\Extension\Payments\Internal\Infrastructure\Webhook\HmacWebhookVerifier;
use Pulsar\Extension\Payments\Internal\Infrastructure\Webhook\InMemoryWebhookEventLog;
use Pulsar\Extension\Payments\PaymentsServiceProvider;
=======
use Pulsar\Extension\Payments\Contract\ClockInterface;
use Pulsar\Extension\Payments\Contract\IdempotencyStoreInterface;
use Pulsar\Extension\Payments\Contract\PaymentProviderInterface;
use Pulsar\Extension\Payments\Contract\WebhookEventLogInterface;
use Pulsar\Extension\Payments\Contract\WebhookVerifierInterface;
use Pulsar\Extension\Payments\Controller\WebhookController;
use Pulsar\Extension\Payments\Gateway\PaymentGateway;
use Pulsar\Extension\Payments\Idempotency\InMemoryIdempotencyStore;
use Pulsar\Extension\Payments\PaymentsServiceProvider;
use Pulsar\Extension\Payments\Provider\NullProvider;
use Pulsar\Extension\Payments\Provider\SimulatorProvider;
use Pulsar\Extension\Payments\Webhook\HmacWebhookVerifier;
use Pulsar\Extension\Payments\Webhook\InMemoryWebhookEventLog;
>>>>>>> main
use Pulsar\Extension\Payments\Webhook\WebhookProcessor;

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
<<<<<<< feat/modular-monolith-payments
        self::assertContains(CreatePaymentIntentHandler::class, $provides);
        self::assertContains(ProcessWebhookHandler::class, $provides);
        self::assertContains(PaymentGateway::class, $provides);
        self::assertContains(PaymentGatewayInterface::class, $provides);
        self::assertContains(WebhookProcessor::class, $provides);
        self::assertContains(WebhookProcessorInterface::class, $provides);
=======
        self::assertContains(PaymentGateway::class, $provides);
        self::assertContains(WebhookProcessor::class, $provides);
>>>>>>> main
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
