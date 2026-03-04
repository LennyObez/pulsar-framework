<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Subscriptions\VerificationResult;

#[CoversClass(VerificationResult::class)]
final class VerificationResultTest extends TestCase
{
    #[Test]
    public function constructorAssignsAllProperties(): void
    {
        $expires = new DateTimeImmutable('2026-12-31');
        $grace = new DateTimeImmutable('2026-12-25');

        $result = new VerificationResult(
            isValid: true,
            expiresAt: $expires,
            gracePeriodUntil: $grace,
            productId: 'com.example.premium',
            autoRenewing: true,
        );

        self::assertTrue($result->isValid);
        self::assertSame($expires, $result->expiresAt);
        self::assertSame($grace, $result->gracePeriodUntil);
        self::assertSame('com.example.premium', $result->productId);
        self::assertTrue($result->autoRenewing);
    }

    #[Test]
    public function invalidFactoryReturnsFalseWithDefaults(): void
    {
        $result = VerificationResult::invalid();

        self::assertFalse($result->isValid);
        self::assertNull($result->expiresAt);
        self::assertNull($result->gracePeriodUntil);
        self::assertSame('', $result->productId);
        self::assertFalse($result->autoRenewing);
    }

    #[Test]
    public function constructorAllowsNullableDates(): void
    {
        $result = new VerificationResult(
            isValid: true,
            expiresAt: null,
            gracePeriodUntil: null,
            productId: 'sku-123',
            autoRenewing: false,
        );

        self::assertTrue($result->isValid);
        self::assertNull($result->expiresAt);
        self::assertNull($result->gracePeriodUntil);
        self::assertFalse($result->autoRenewing);
    }

    #[Test]
    public function validResultWithNonRenewing(): void
    {
        $result = new VerificationResult(
            isValid: true,
            expiresAt: new DateTimeImmutable('+30 days'),
            gracePeriodUntil: null,
            productId: 'com.app.plan',
            autoRenewing: false,
        );

        self::assertTrue($result->isValid);
        self::assertFalse($result->autoRenewing);
        self::assertSame('com.app.plan', $result->productId);
    }
}
