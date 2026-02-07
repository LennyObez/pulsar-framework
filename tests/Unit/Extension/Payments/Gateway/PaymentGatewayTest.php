<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Gateway;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Payments\Clock\FixedClock;
use Pulsar\Extension\Payments\Config\IdempotencyConfig;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Config\WebhookConfig;
use Pulsar\Extension\Payments\Config\WebhookLogConfig;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;
use Pulsar\Extension\Payments\Exception\IdempotencyException;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use Pulsar\Extension\Payments\Gateway\PaymentGateway;
use Pulsar\Extension\Payments\Idempotency\InMemoryIdempotencyStore;
use Pulsar\Extension\Payments\Provider\NullProvider;
use Pulsar\Extension\Payments\Provider\SimulatorProvider;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditSinkInterface;

#[CoversClass(PaymentGateway::class)]
final class PaymentGatewayTest extends TestCase
{
    private FixedClock $clock;
    private MetricRegistry $metricRegistry;
    private InMemoryIdempotencyStore $idempotencyStore;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(new DateTimeImmutable('2025-01-01T00:00:00Z'));
        $this->metricRegistry = new MetricRegistry();
        $this->idempotencyStore = new InMemoryIdempotencyStore();
    }

    #[Test]
    public function createIntentSucceeds(): void
    {
        $gateway = $this->createGateway();
        $amount = Money::of(5000, Currency::USD);

        $intent = $gateway->createIntent($amount, 'idem-key-1');

        self::assertSame(PaymentIntentStatus::Created, $intent->status);
        self::assertNotEmpty($intent->id);
    }

    #[Test]
    public function createIntentIdempotencyReplay(): void
    {
        $gateway = $this->createGateway();
        $amount = Money::of(5000, Currency::USD);

        $first = $gateway->createIntent($amount, 'idem-key-replay');
        $second = $gateway->createIntent($amount, 'idem-key-replay');

        self::assertSame($first->id, $second->id);

        // Check replay metric
        $replayCounter = $this->metricRegistry->counter('payments_idempotency_replays_total');
        self::assertSame(1.0, $replayCounter->value(new LabelSet([
            'provider' => 'null',
            'operation' => 'createIntent',
        ])));
    }

    #[Test]
    public function createIntentIdempotencyMismatch(): void
    {
        $gateway = $this->createGateway();

        $gateway->createIntent(Money::of(5000, Currency::USD), 'idem-key-mismatch');

        $this->expectException(IdempotencyException::class);
        $this->expectExceptionMessage('different parameters');

        // Same key, different amount
        $gateway->createIntent(Money::of(6000, Currency::USD), 'idem-key-mismatch');
    }

    #[Test]
    public function invalidIdempotencyKeyEmptyThrows(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(IdempotencyException::class);
        $this->expectExceptionMessage('Invalid idempotency key');

        $gateway->createIntent(Money::of(1000, Currency::USD), '');
    }

    #[Test]
    public function invalidIdempotencyKeyWithWhitespaceThrows(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(IdempotencyException::class);
        $this->expectExceptionMessage('invalid characters');

        $gateway->createIntent(Money::of(1000, Currency::USD), 'key with spaces');
    }

    #[Test]
    public function invalidIdempotencyKeyTooLongThrows(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(IdempotencyException::class);

        $gateway->createIntent(Money::of(1000, Currency::USD), str_repeat('a', 257));
    }

    #[Test]
    public function providerErrorReleasesIdempotencyClaim(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $gateway = $this->createGatewayWith($provider);

        // 9994 triggers timeout
        try {
            $gateway->createIntent(Money::of(9994, Currency::USD), 'idem-key-error');
        } catch (PaymentProviderException) {
            // Expected
        }

        // Key should be released — can retry
        $intent = $gateway->createIntent(Money::of(5000, Currency::USD), 'idem-key-error');

        self::assertSame(PaymentIntentStatus::Created, $intent->status);
    }

    #[Test]
    public function metricsIncrementOnSuccess(): void
    {
        $gateway = $this->createGateway();
        $gateway->createIntent(Money::of(1000, Currency::USD), 'idem-metric');

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
        $gateway = $this->createGatewayWith($provider);

        try {
            $gateway->createIntent(Money::of(9994, Currency::USD), 'idem-error-metric');
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

    #[Test]
    public function captureIntentSucceeds(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $gateway = $this->createGatewayWith($provider);

        $intent = $gateway->createIntent(Money::of(2000, Currency::USD), 'idem-cap-1');
        $charge = $gateway->captureIntent($intent->id, 'idem-cap-2');

        self::assertSame($intent->id, $charge->intentId);
    }

    #[Test]
    public function refundSucceeds(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $gateway = $this->createGatewayWith($provider);

        $intent = $gateway->createIntent(Money::of(2000, Currency::USD), 'idem-ref-1');
        $charge = $gateway->captureIntent($intent->id, 'idem-ref-2');
        $refund = $gateway->refund($charge->id, null, 'idem-ref-3');

        self::assertSame($charge->id, $refund->chargeId);
    }

    #[Test]
    public function cancelIntentSucceeds(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $gateway = $this->createGatewayWith($provider);

        $intent = $gateway->createIntent(Money::of(2000, Currency::USD), 'idem-cancel-1');
        $cancelled = $gateway->cancelIntent($intent->id, 'idem-cancel-2');

        self::assertSame(PaymentIntentStatus::Cancelled, $cancelled->status);
    }

    #[Test]
    public function getIntentDelegatesToProvider(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $gateway = $this->createGatewayWith($provider);

        $intent = $gateway->createIntent(Money::of(2000, Currency::USD), 'idem-get-1');
        $retrieved = $gateway->getIntent($intent->id);

        self::assertSame($intent->id, $retrieved->id);
    }

    private function createGateway(): PaymentGateway
    {
        $provider = new NullProvider($this->clock);

        return $this->createGatewayWith($provider);
    }

    private function createGatewayWith(\Pulsar\Extension\Payments\Contract\PaymentProviderInterface $provider): PaymentGateway
    {
        $sink = new class implements AuditSinkInterface {
            /** @var list<\Pulsar\Security\Audit\AuditEntry> */
            public array $entries = [];

            public function write(\Pulsar\Security\Audit\AuditEntry $entry): void
            {
                $this->entries[] = $entry;
            }
        };

        $auditLogger = new AuditLogger($sink, 'test-audit-key-1234');

        return new PaymentGateway(
            provider: $provider,
            idempotencyStore: $this->idempotencyStore,
            auditLogger: $auditLogger,
            metricRegistry: $this->metricRegistry,
            logger: new NullLogger(),
            clock: $this->clock,
            config: $this->createConfig(),
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
        );
    }
}
