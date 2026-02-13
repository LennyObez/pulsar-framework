<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Subscriptions;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Subscriptions\VerificationResult;

final class VerificationResultTest extends TestCase
{
    #[Test]
    public function invalidFactoryCreatesResultWithDefaults(): void
    {
        $result = VerificationResult::invalid();

        self::assertFalse($result->isValid);
        self::assertNull($result->expiresAt);
        self::assertNull($result->gracePeriodUntil);
        self::assertSame('', $result->productId);
        self::assertFalse($result->autoRenewing);
    }

    #[Test]
    public function constructorStoresAllFields(): void
    {
        $expiresAt = new DateTimeImmutable('+30 days');
        $gracePeriod = new DateTimeImmutable('+7 days');

        $result = new VerificationResult(
            isValid: true,
            expiresAt: $expiresAt,
            gracePeriodUntil: $gracePeriod,
            productId: 'premium_monthly',
            autoRenewing: true,
        );

        self::assertTrue($result->isValid);
        self::assertSame($expiresAt, $result->expiresAt);
        self::assertSame($gracePeriod, $result->gracePeriodUntil);
        self::assertSame('premium_monthly', $result->productId);
        self::assertTrue($result->autoRenewing);
    }

    #[Test]
    public function constructorAllowsNullDates(): void
    {
        $result = new VerificationResult(
            isValid: true,
            expiresAt: null,
            gracePeriodUntil: null,
            productId: 'lifetime',
            autoRenewing: false,
        );

        self::assertTrue($result->isValid);
        self::assertNull($result->expiresAt);
        self::assertNull($result->gracePeriodUntil);
        self::assertFalse($result->autoRenewing);
    }
}
