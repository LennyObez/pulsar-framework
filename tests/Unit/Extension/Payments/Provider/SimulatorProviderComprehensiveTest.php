<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Provider;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Contracts\PaymentProviderInterface;
use Pulsar\Extension\Payments\Domain\ChargeStatus;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;
use Pulsar\Extension\Payments\Domain\RefundStatus;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use Pulsar\Extension\Payments\Internal\Infrastructure\Clock\FixedClock;
use Pulsar\Extension\Payments\Internal\Infrastructure\Provider\SimulatorProvider;

use function strlen;

/**
 * Comprehensive tests for SimulatorProvider covering partial refunds,
 * metadata passthrough, deterministic ID generation, dispute/refund flags,
 * error type assertions, and full lifecycle scenarios.
 */
#[CoversClass(SimulatorProvider::class)]
final class SimulatorProviderComprehensiveTest extends TestCase
{
    private FixedClock $clock;
    private SimulatorProvider $provider;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(new DateTimeImmutable('2025-06-15T12:00:00Z'));
        $this->provider = new SimulatorProvider($this->clock);
    }

    // --- Provider name ---

    #[Test]
    public function nameReturnsSimulator(): void
    {
        self::assertSame('simulator', $this->provider->name());
    }

    // --- Successful intent creation ---

    #[Test]
    public function createIntentReturnsCreatedStatusWithCorrectFields(): void
    {
        $amount = Money::of(5000, Currency::USD);
        $intent = $this->provider->createIntent($amount, 'idem-1', ['order_id' => 'ORD-123']);

        self::assertSame(PaymentIntentStatus::Created, $intent->status);
        self::assertSame('simulator', $intent->provider);
        self::assertSame('idem-1', $intent->idempotencyKey);
        self::assertSame(5000, $intent->amount->amount);
        self::assertSame(Currency::USD, $intent->amount->currency);
        self::assertSame(['order_id' => 'ORD-123'], $intent->metadata);
        self::assertSame(32, strlen($intent->id));
    }

    #[Test]
    public function createIntentUsesClockForTimestamp(): void
    {
        $intent = $this->provider->createIntent(Money::of(100, Currency::EUR), 'idem-time');

        self::assertSame('2025-06-15T12:00:00+00:00', $intent->createdAt->format('c'));
    }

    // --- Test vector decline scenarios ---

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function declineTestVectorsProvider(): array
    {
        return [
            '9999 insufficient_funds' => [9999, 'declined', 'insufficient_funds'],
            '9998 card_expired' => [9998, 'declined', 'card_expired'],
            '9997 card_declined' => [9997, 'declined', 'card_declined'],
            '9996 processing_error' => [9996, 'declined', 'processing_error'],
            '9995 fraud_suspected' => [9995, 'declined', 'fraud_suspected'],
        ];
    }

    #[Test]
    #[DataProvider('declineTestVectorsProvider')]
    public function testVectorDeclines(int $amount, string $errorType, string $reason): void
    {
        try {
            $this->provider->createIntent(Money::of($amount, Currency::USD), 'key-' . $amount);
            self::fail('Expected PaymentProviderException was not thrown');
        } catch (PaymentProviderException $e) {
            self::assertSame($errorType, $e->errorType);
            self::assertStringContainsString($reason, $e->getMessage());
        }
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function exceptionTestVectorsProvider(): array
    {
        return [
            '9994 timeout' => [9994, 'timeout'],
            '9993 network_error' => [9993, 'network_error'],
            '9992 rate_limited' => [9992, 'rate_limited'],
        ];
    }

    #[Test]
    #[DataProvider('exceptionTestVectorsProvider')]
    public function testVectorExceptions(int $amount, string $errorType): void
    {
        try {
            $this->provider->createIntent(Money::of($amount, Currency::USD), 'key-' . $amount);
            self::fail('Expected PaymentProviderException was not thrown');
        } catch (PaymentProviderException $e) {
            self::assertSame($errorType, $e->errorType);
        }
    }

    // --- Capture lifecycle ---

    #[Test]
    public function captureIntentCreatesSucceededCharge(): void
    {
        $intent = $this->provider->createIntent(Money::of(2000, Currency::USD), 'key-cap');
        $charge = $this->provider->captureIntent($intent->id, 'key-cap-2');

        self::assertSame(ChargeStatus::Succeeded, $charge->status);
        self::assertSame($intent->id, $charge->intentId);
        self::assertSame(2000, $charge->amount->amount);
        self::assertSame(Currency::USD, $charge->amount->currency);
        self::assertSame('simulator', $charge->provider);
        self::assertSame(32, strlen($charge->id));
    }

    #[Test]
    public function captureIntentTransitionsIntentToCaptured(): void
    {
        $intent = $this->provider->createIntent(Money::of(2000, Currency::USD), 'key-trans');
        $this->provider->captureIntent($intent->id, 'key-trans-2');

        $retrieved = $this->provider->getIntent($intent->id);
        self::assertSame(PaymentIntentStatus::Captured, $retrieved->status);
    }

    #[Test]
    public function captureNonExistentIntentThrows(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('not found');

        $this->provider->captureIntent('nonexistent-id', 'key-miss');
    }

    // --- Cancel lifecycle ---

    #[Test]
    public function cancelIntentTransitionsToCancelled(): void
    {
        $intent = $this->provider->createIntent(Money::of(1000, Currency::USD), 'key-cancel');
        $cancelled = $this->provider->cancelIntent($intent->id, 'key-cancel-2');

        self::assertSame(PaymentIntentStatus::Cancelled, $cancelled->status);
        self::assertSame($intent->id, $cancelled->id);
    }

    #[Test]
    public function cancelNonExistentIntentThrows(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('not found');

        $this->provider->cancelIntent('nonexistent-id', 'key-miss');
    }

    // --- Refund lifecycle ---

    #[Test]
    public function fullRefundUsesChargeAmount(): void
    {
        $intent = $this->provider->createIntent(Money::of(5000, Currency::USD), 'key-full-ref');
        $charge = $this->provider->captureIntent($intent->id, 'key-full-ref-2');

        $refund = $this->provider->refund($charge->id, null, 'key-full-ref-3');

        self::assertSame(RefundStatus::Succeeded, $refund->status);
        self::assertSame($charge->id, $refund->chargeId);
        self::assertSame(5000, $refund->amount->amount);
        self::assertSame(Currency::USD, $refund->amount->currency);
        self::assertSame('simulator', $refund->provider);
    }

    #[Test]
    public function partialRefundUsesSpecifiedAmount(): void
    {
        $intent = $this->provider->createIntent(Money::of(5000, Currency::USD), 'key-part-ref');
        $charge = $this->provider->captureIntent($intent->id, 'key-part-ref-2');

        $partialAmount = Money::of(2000, Currency::USD);
        $refund = $this->provider->refund($charge->id, $partialAmount, 'key-part-ref-3');

        self::assertSame(RefundStatus::Succeeded, $refund->status);
        self::assertSame(2000, $refund->amount->amount);
    }

    #[Test]
    public function refundNonExistentChargeThrows(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('not found');

        $this->provider->refund('nonexistent-charge', null, 'key-miss');
    }

    // --- Test vector 4242: dispute flag ---

    #[Test]
    public function amount4242SetsDisputeFlag(): void
    {
        $intent = $this->provider->createIntent(Money::of(4242, Currency::USD), 'key-4242');
        $charge = $this->provider->captureIntent($intent->id, 'key-4242-cap');

        self::assertTrue($this->provider->hasDisputeFlag($charge->id));
    }

    #[Test]
    public function normalAmountDoesNotSetDisputeFlag(): void
    {
        $intent = $this->provider->createIntent(Money::of(1000, Currency::USD), 'key-normal');
        $charge = $this->provider->captureIntent($intent->id, 'key-normal-cap');

        self::assertFalse($this->provider->hasDisputeFlag($charge->id));
    }

    #[Test]
    public function hasDisputeFlagReturnsFalseForUnknownCharge(): void
    {
        self::assertFalse($this->provider->hasDisputeFlag('nonexistent'));
    }

    // --- Test vector 3030: refund-fail flag ---

    #[Test]
    public function amount3030CausesRefundFailure(): void
    {
        $intent = $this->provider->createIntent(Money::of(3030, Currency::USD), 'key-3030');
        $charge = $this->provider->captureIntent($intent->id, 'key-3030-cap');

        try {
            $this->provider->refund($charge->id, null, 'key-3030-ref');
            self::fail('Expected PaymentProviderException was not thrown');
        } catch (PaymentProviderException $e) {
            self::assertSame('refund_failed', $e->errorType);
            self::assertStringContainsString('simulated_refund_failure', $e->getMessage());
        }
    }

    #[Test]
    public function amount4242AllowsRefund(): void
    {
        $intent = $this->provider->createIntent(Money::of(4242, Currency::USD), 'key-4242-ref');
        $charge = $this->provider->captureIntent($intent->id, 'key-4242-ref-cap');

        $refund = $this->provider->refund($charge->id, null, 'key-4242-ref-3');

        // 4242 flags for dispute but allows refund
        self::assertSame(RefundStatus::Succeeded, $refund->status);
    }

    // --- Get operations ---

    #[Test]
    public function getIntentReturnsStoredIntent(): void
    {
        $intent = $this->provider->createIntent(Money::of(100, Currency::EUR), 'key-get');

        $retrieved = $this->provider->getIntent($intent->id);

        self::assertSame($intent->id, $retrieved->id);
        self::assertSame(PaymentIntentStatus::Created, $retrieved->status);
    }

    #[Test]
    public function getIntentThrowsForNonExistentId(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('PaymentIntent not found');

        $this->provider->getIntent('does-not-exist');
    }

    #[Test]
    public function getChargeReturnsStoredCharge(): void
    {
        $intent = $this->provider->createIntent(Money::of(100, Currency::EUR), 'key-get-c');
        $charge = $this->provider->captureIntent($intent->id, 'key-get-c-2');

        $retrieved = $this->provider->getCharge($charge->id);

        self::assertSame($charge->id, $retrieved->id);
        self::assertSame(ChargeStatus::Succeeded, $retrieved->status);
    }

    #[Test]
    public function getChargeThrowsForNonExistentId(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Charge not found');

        $this->provider->getCharge('does-not-exist');
    }

    #[Test]
    public function getRefundReturnsStoredRefund(): void
    {
        $intent = $this->provider->createIntent(Money::of(100, Currency::EUR), 'key-get-r');
        $charge = $this->provider->captureIntent($intent->id, 'key-get-r-2');
        $refund = $this->provider->refund($charge->id, null, 'key-get-r-3');

        $retrieved = $this->provider->getRefund($refund->id);

        self::assertSame($refund->id, $retrieved->id);
        self::assertSame(RefundStatus::Succeeded, $retrieved->status);
    }

    #[Test]
    public function getRefundThrowsForNonExistentId(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Refund not found');

        $this->provider->getRefund('does-not-exist');
    }

    // --- Deterministic ID generation ---

    #[Test]
    public function sameIdempotencyKeyProducesSameId(): void
    {
        $intent1 = $this->provider->createIntent(Money::of(100, Currency::USD), 'same-key');

        // Create a fresh provider to reset state
        $provider2 = new SimulatorProvider($this->clock);
        $intent2 = $provider2->createIntent(Money::of(100, Currency::USD), 'same-key');

        self::assertSame($intent1->id, $intent2->id);
    }

    #[Test]
    public function differentIdempotencyKeysProduceDifferentIds(): void
    {
        $intent1 = $this->provider->createIntent(Money::of(100, Currency::USD), 'key-a');

        $provider2 = new SimulatorProvider($this->clock);
        $intent2 = $provider2->createIntent(Money::of(100, Currency::USD), 'key-b');

        self::assertNotSame($intent1->id, $intent2->id);
    }

    #[Test]
    public function idsAre32CharHexStrings(): void
    {
        $intent = $this->provider->createIntent(Money::of(100, Currency::USD), 'key-hex');
        $charge = $this->provider->captureIntent($intent->id, 'key-hex-2');
        $refund = $this->provider->refund($charge->id, null, 'key-hex-3');

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $intent->id);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $charge->id);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $refund->id);
    }

    #[Test]
    public function captureAndRefundIdsIncludeResourceId(): void
    {
        $intent = $this->provider->createIntent(Money::of(100, Currency::USD), 'key-res');
        $charge = $this->provider->captureIntent($intent->id, 'key-res-2');

        // Charge ID incorporates intentId, so different intents produce different charge IDs
        $intent2 = $this->provider->createIntent(Money::of(200, Currency::USD), 'key-res-3');
        $charge2 = $this->provider->captureIntent($intent2->id, 'key-res-2');

        self::assertNotSame($charge->id, $charge2->id);
    }

    // --- Different currency ---

    #[Test]
    public function createIntentWithJpyCurrency(): void
    {
        $amount = Money::of(1000, Currency::JPY);
        $intent = $this->provider->createIntent($amount, 'key-jpy');

        self::assertSame(Currency::JPY, $intent->amount->currency);
        self::assertSame(1000, $intent->amount->amount);
    }

    // --- Empty metadata ---

    #[Test]
    public function createIntentWithEmptyMetadataDefaults(): void
    {
        $intent = $this->provider->createIntent(Money::of(100, Currency::USD), 'key-meta');

        self::assertSame([], $intent->metadata);
    }

    // --- PaymentProviderInterface contract ---

    #[Test]
    public function implementsPaymentProviderInterface(): void
    {
        self::assertInstanceOf(
            PaymentProviderInterface::class,
            $this->provider,
        );
    }

    // --- Normal amounts (non-test-vector) succeed ---

    /**
     * @return array<string, array{int}>
     */
    public static function normalAmountProvider(): array
    {
        return [
            'zero' => [0],
            'one cent' => [1],
            'typical amount' => [2500],
            'just below first decline' => [9991],
            'above all decline vectors' => [10000],
            'large amount' => [999999],
        ];
    }

    #[Test]
    #[DataProvider('normalAmountProvider')]
    public function normalAmountsSucceed(int $amount): void
    {
        $intent = $this->provider->createIntent(
            Money::of($amount, Currency::USD),
            'key-normal-' . $amount,
        );

        self::assertSame(PaymentIntentStatus::Created, $intent->status);
    }
}
