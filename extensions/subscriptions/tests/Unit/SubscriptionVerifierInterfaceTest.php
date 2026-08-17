<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\SubscriptionVerifierInterface;
use Pulsar\Extension\Subscriptions\VerificationResult;

#[CoversNothing]
final class SubscriptionVerifierInterfaceTest extends TestCase
{
    #[Test]
    public function stubCanReturnValidVerificationResult(): void
    {
        $result = new VerificationResult(
            isValid: true,
            expiresAt: new DateTimeImmutable('2026-12-31'),
            gracePeriodUntil: null,
            productId: 'com.app.premium',
            autoRenewing: true,
        );

        $stub = $this->createStub(SubscriptionVerifierInterface::class);
        $stub->method('verify')->willReturn($result);

        $actual = $stub->verify(Store::Google, 'purchase-token-abc');

        self::assertTrue($actual->isValid);
        self::assertSame('com.app.premium', $actual->productId);
        self::assertTrue($actual->autoRenewing);
        self::assertNotNull($actual->expiresAt);
    }

    #[Test]
    public function stubCanReturnInvalidVerificationResult(): void
    {
        $stub = $this->createStub(SubscriptionVerifierInterface::class);
        $stub->method('verify')->willReturn(VerificationResult::invalid());

        $actual = $stub->verify(Store::Apple, 'bad-token');

        self::assertFalse($actual->isValid);
        self::assertSame('', $actual->productId);
        self::assertFalse($actual->autoRenewing);
        self::assertNull($actual->expiresAt);
        self::assertNull($actual->gracePeriodUntil);
    }

    #[Test]
    public function stubCanReturnResultWithGracePeriod(): void
    {
        $result = new VerificationResult(
            isValid: true,
            expiresAt: new DateTimeImmutable('2026-04-01'),
            gracePeriodUntil: new DateTimeImmutable('2026-04-07'),
            productId: 'com.app.pro',
            autoRenewing: false,
        );

        $stub = $this->createStub(SubscriptionVerifierInterface::class);
        $stub->method('verify')->willReturn($result);

        $actual = $stub->verify(Store::Google, 'grace-token');

        self::assertTrue($actual->isValid);
        self::assertNotNull($actual->gracePeriodUntil);
        self::assertFalse($actual->autoRenewing);
    }
}
