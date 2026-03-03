<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Features\CreatePaymentIntent;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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
use Pulsar\Extension\Payments\Contracts\PaymentProviderInterface;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use Pulsar\Extension\Payments\Features\CreatePaymentIntent\CreatePaymentIntentHandler;
use Pulsar\Extension\Payments\Features\CreatePaymentIntent\CreatePaymentIntentRequest;
use Pulsar\Extension\Payments\Internal\Infrastructure\Clock\FixedClock;
use Pulsar\Extension\Payments\Internal\Infrastructure\Provider\NullProvider;
use Pulsar\Extension\Payments\Internal\Infrastructure\Provider\SimulatorProvider;
use Pulsar\Idempotency\Exception\IdempotencyException;
use Pulsar\Idempotency\InMemoryIdempotencyStore;
use Pulsar\Idempotency\SignedIdempotencyEnvelope;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditSinkInterface;
use Pulsar\Security\Crypto\MasterKey;

use function random_bytes;
use function sodium_bin2hex;

#[CoversClass(CreatePaymentIntentHandler::class)]
final class CreatePaymentIntentHandlerTest extends TestCase
{
    private FixedClock $clock;
    private MetricRegistry $metricRegistry;
    private InMemoryIdempotencyStore $idempotencyStore;
    private SignedIdempotencyEnvelope $envelope;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(new DateTimeImmutable('2025-01-01T00:00:00Z'));
        $this->metricRegistry = new MetricRegistry();
        $this->idempotencyStore = new InMemoryIdempotencyStore();
        $this->envelope = new SignedIdempotencyEnvelope(
            MasterKey::fromHex(sodium_bin2hex(random_bytes(32))),
        );
    }

    #[Test]
    public function executeSucceeds(): void
    {
        $handler = $this->createHandler();
        $request = new CreatePaymentIntentRequest(Money::of(5000, Currency::USD), 'idem-key-1');

        $result = $handler->execute($request);

        self::assertSame(PaymentIntentStatus::Created, $result->intent->status);
        self::assertNotEmpty($result->intent->id);
        self::assertFalse($result->replayed);
    }

    #[Test]
    public function executeIdempotencyReplay(): void
    {
        $handler = $this->createHandler();
        $request = new CreatePaymentIntentRequest(Money::of(5000, Currency::USD), 'idem-key-replay');

        $first = $handler->execute($request);
        $second = $handler->execute($request);

        self::assertSame($first->intent->id, $second->intent->id);
        self::assertTrue($second->replayed);

        $replayCounter = $this->metricRegistry->counter('payments_idempotency_replays_total');
        self::assertSame(1.0, $replayCounter->value(new LabelSet([
            'provider' => 'null',
            'operation' => 'createIntent',
        ])));
    }

    #[Test]
    public function executeIdempotencyMismatch(): void
    {
        $handler = $this->createHandler();

        $handler->execute(new CreatePaymentIntentRequest(Money::of(5000, Currency::USD), 'idem-key-mismatch'));

        $this->expectException(IdempotencyException::class);
        $this->expectExceptionMessage('different parameters');

        $handler->execute(new CreatePaymentIntentRequest(Money::of(6000, Currency::USD), 'idem-key-mismatch'));
    }

    #[Test]
    public function executeInvalidIdempotencyKeyEmptyThrows(): void
    {
        $handler = $this->createHandler();

        $this->expectException(IdempotencyException::class);
        $this->expectExceptionMessage('Invalid idempotency key');

        $handler->execute(new CreatePaymentIntentRequest(Money::of(1000, Currency::USD), ''));
    }

    #[Test]
    public function executeInvalidIdempotencyKeyWithWhitespaceThrows(): void
    {
        $handler = $this->createHandler();

        $this->expectException(IdempotencyException::class);
        $this->expectExceptionMessage('invalid characters');

        $handler->execute(new CreatePaymentIntentRequest(Money::of(1000, Currency::USD), 'key with spaces'));
    }

    #[Test]
    public function providerErrorReleasesIdempotencyClaim(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $handler = $this->createHandlerWith($provider);

        // 9994 triggers timeout
        try {
            $handler->execute(new CreatePaymentIntentRequest(Money::of(9994, Currency::USD), 'idem-key-error'));
        } catch (PaymentProviderException) {
            // Expected
        }

        // Key should be released — can retry with different params
        $result = $handler->execute(new CreatePaymentIntentRequest(Money::of(5000, Currency::USD), 'idem-key-error'));

        self::assertSame(PaymentIntentStatus::Created, $result->intent->status);
    }

    #[Test]
    public function metricsIncrementOnSuccess(): void
    {
        $handler = $this->createHandler();
        $handler->execute(new CreatePaymentIntentRequest(Money::of(1000, Currency::USD), 'idem-metric'));

        $counter = $this->metricRegistry->counter('payments_intents_total');
        self::assertSame(1.0, $counter->value(new LabelSet([
            'provider' => 'null',
            'currency' => 'USD',
            'status' => 'created',
        ])));
    }

    #[Test]
    public function metricsIncrementOnProviderError(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $handler = $this->createHandlerWith($provider);

        try {
            $handler->execute(new CreatePaymentIntentRequest(Money::of(9994, Currency::USD), 'idem-error-metric'));
        } catch (PaymentProviderException) {
            // Expected
        }

        $errorCounter = $this->metricRegistry->counter('payments_provider_errors_total');
        self::assertSame(1.0, $errorCounter->value(new LabelSet([
            'provider' => 'simulator',
            'operation' => 'createIntent',
            'error_type' => 'timeout',
        ])));
    }

    private function createHandler(): CreatePaymentIntentHandler
    {
        return $this->createHandlerWith(new NullProvider($this->clock));
    }

    private function createHandlerWith(PaymentProviderInterface $provider): CreatePaymentIntentHandler
    {
        $sink = new class implements AuditSinkInterface {
            /** @var list<AuditEntry> */
            public array $entries = [];

            public function write(AuditEntry $entry): void
            {
                $this->entries[] = $entry;
            }
        };

        return new CreatePaymentIntentHandler(
            provider: $provider,
            idempotencyStore: $this->idempotencyStore,
            auditLogger: new AuditLogger($sink, 'test-audit-key-1234'),
            metricRegistry: $this->metricRegistry,
            logger: new NullLogger(),
            clock: $this->clock,
            config: $this->createConfig(),
            envelope: $this->envelope,
        );
    }

    private function createConfig(): PaymentsConfig
    {
        return new PaymentsConfig(
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
    }
}
