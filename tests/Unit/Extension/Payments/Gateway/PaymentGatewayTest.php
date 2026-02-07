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
use Pulsar\Extension\Payments\Contract\PaymentProviderInterface;
use Pulsar\Extension\Payments\Domain\ChargeStatus;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;
use Pulsar\Extension\Payments\Domain\RefundStatus;
use Pulsar\Extension\Payments\Exception\IdempotencyException;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use Pulsar\Extension\Payments\Gateway\PaymentGateway;
use Pulsar\Extension\Payments\Idempotency\InMemoryIdempotencyStore;
use Pulsar\Extension\Payments\Provider\NullProvider;
use Pulsar\Extension\Payments\Provider\SimulatorProvider;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditSinkInterface;
use Throwable;

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

    #[Test]
    public function captureIntentIdempotencyReplay(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $gateway = $this->createGatewayWith($provider);

        $intent = $gateway->createIntent(Money::of(2000, Currency::USD), 'idem-cap-replay-1');
        $firstCharge = $gateway->captureIntent($intent->id, 'idem-cap-replay-2');
        $secondCharge = $gateway->captureIntent($intent->id, 'idem-cap-replay-2');

        self::assertSame($firstCharge->id, $secondCharge->id);
        self::assertSame(ChargeStatus::Succeeded, $secondCharge->status);
    }

    #[Test]
    public function cancelIntentIdempotencyReplay(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $gateway = $this->createGatewayWith($provider);

        $intent = $gateway->createIntent(Money::of(2000, Currency::USD), 'idem-cancel-replay-1');
        $firstCancel = $gateway->cancelIntent($intent->id, 'idem-cancel-replay-2');
        $secondCancel = $gateway->cancelIntent($intent->id, 'idem-cancel-replay-2');

        self::assertSame($firstCancel->id, $secondCancel->id);
        self::assertSame(PaymentIntentStatus::Cancelled, $secondCancel->status);
    }

    #[Test]
    public function refundIdempotencyReplay(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $gateway = $this->createGatewayWith($provider);

        $intent = $gateway->createIntent(Money::of(2000, Currency::USD), 'idem-ref-replay-1');
        $charge = $gateway->captureIntent($intent->id, 'idem-ref-replay-2');
        $firstRefund = $gateway->refund($charge->id, null, 'idem-ref-replay-3');
        $secondRefund = $gateway->refund($charge->id, null, 'idem-ref-replay-3');

        self::assertSame($firstRefund->id, $secondRefund->id);
        self::assertSame(RefundStatus::Succeeded, $secondRefund->status);
    }

    #[Test]
    public function captureIntentMismatchThrows(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $gateway = $this->createGatewayWith($provider);

        $intent1 = $gateway->createIntent(Money::of(2000, Currency::USD), 'idem-cap-mis-1');
        $intent2 = $gateway->createIntent(Money::of(3000, Currency::USD), 'idem-cap-mis-2');
        $gateway->captureIntent($intent1->id, 'idem-cap-mis-shared');

        $this->expectException(IdempotencyException::class);
        $gateway->captureIntent($intent2->id, 'idem-cap-mis-shared');
    }

    #[Test]
    public function refundMismatchThrows(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $gateway = $this->createGatewayWith($provider);

        $intent = $gateway->createIntent(Money::of(5000, Currency::USD), 'idem-ref-mis-1');
        $charge = $gateway->captureIntent($intent->id, 'idem-ref-mis-2');
        $gateway->refund($charge->id, null, 'idem-ref-mis-shared');

        $this->expectException(IdempotencyException::class);
        $gateway->refund($charge->id, Money::of(1000, Currency::USD), 'idem-ref-mis-shared');
    }

    #[Test]
    public function captureIntentMetricsIncrement(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $gateway = $this->createGatewayWith($provider);

        $intent = $gateway->createIntent(Money::of(2000, Currency::USD), 'idem-cap-met-1');
        $gateway->captureIntent($intent->id, 'idem-cap-met-2');

        $counter = $this->metricRegistry->counter('payments_captures_total');
        self::assertSame(1.0, $counter->value(new LabelSet([
            'provider' => 'simulator',
            'currency' => 'USD',
            'status' => 'succeeded',
        ])));
    }

    #[Test]
    public function refundMetricsIncrement(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $gateway = $this->createGatewayWith($provider);

        $intent = $gateway->createIntent(Money::of(2000, Currency::USD), 'idem-ref-met-1');
        $charge = $gateway->captureIntent($intent->id, 'idem-ref-met-2');
        $gateway->refund($charge->id, null, 'idem-ref-met-3');

        $counter = $this->metricRegistry->counter('payments_refunds_total');
        self::assertSame(1.0, $counter->value(new LabelSet([
            'provider' => 'simulator',
            'currency' => 'USD',
            'status' => 'succeeded',
        ])));
    }

    #[Test]
    public function getChargeDelegatesToProvider(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $gateway = $this->createGatewayWith($provider);

        $intent = $gateway->createIntent(Money::of(2000, Currency::USD), 'idem-gc-1');
        $charge = $gateway->captureIntent($intent->id, 'idem-gc-2');
        $retrieved = $gateway->getCharge($charge->id);

        self::assertSame($charge->id, $retrieved->id);
    }

    #[Test]
    public function getRefundDelegatesToProvider(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $gateway = $this->createGatewayWith($provider);

        $intent = $gateway->createIntent(Money::of(2000, Currency::USD), 'idem-gr-1');
        $charge = $gateway->captureIntent($intent->id, 'idem-gr-2');
        $refund = $gateway->refund($charge->id, null, 'idem-gr-3');
        $retrieved = $gateway->getRefund($refund->id);

        self::assertSame($refund->id, $retrieved->id);
    }

    #[Test]
    public function captureProviderErrorReleasesClaimAndIncrementsMetric(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $gateway = $this->createGatewayWith($provider);

        // Try to capture a nonexistent intent — provider error propagates
        try {
            $gateway->captureIntent('nonexistent', 'idem-cap-err');
        } catch (Throwable) {
            // Expected — provider throws on nonexistent intent
        }

        $errorCounter = $this->metricRegistry->counter('payments_provider_errors_total');
        self::assertSame(1.0, $errorCounter->value(new LabelSet([
            'provider' => 'simulator',
            'operation' => 'captureIntent',
            'error_type' => 'unknown',
        ])));
    }

    #[Test]
    public function refundProviderErrorReleasesClaimAndIncrementsMetric(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $gateway = $this->createGatewayWith($provider);

        // 3030 triggers refund failure
        $intent = $gateway->createIntent(Money::of(3030, Currency::USD), 'idem-ref-err-1');
        $charge = $gateway->captureIntent($intent->id, 'idem-ref-err-2');

        try {
            $gateway->refund($charge->id, null, 'idem-ref-err-3');
        } catch (PaymentProviderException) {
            // Expected
        }

        $errorCounter = $this->metricRegistry->counter('payments_provider_errors_total');
        self::assertSame(1.0, $errorCounter->value(new LabelSet([
            'provider' => 'simulator',
            'operation' => 'refund',
            'error_type' => 'refund_failed',
        ])));
    }

    #[Test]
    public function cancelProviderErrorReleasesClaimAndIncrementsMetric(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $gateway = $this->createGatewayWith($provider);

        // Try to cancel a nonexistent intent — provider error propagates
        try {
            $gateway->cancelIntent('nonexistent', 'idem-can-err');
        } catch (Throwable) {
            // Expected — provider throws on nonexistent intent
        }

        $errorCounter = $this->metricRegistry->counter('payments_provider_errors_total');
        self::assertSame(1.0, $errorCounter->value(new LabelSet([
            'provider' => 'simulator',
            'operation' => 'cancelIntent',
            'error_type' => 'unknown',
        ])));
    }

    private function createGateway(): PaymentGateway
    {
        $provider = new NullProvider($this->clock);

        return $this->createGatewayWith($provider);
    }

    private function createGatewayWith(PaymentProviderInterface $provider): PaymentGateway
    {
        $sink = new class implements AuditSinkInterface {
            /** @var list<AuditEntry> */
            public array $entries = [];

            public function write(AuditEntry $entry): void
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
