<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\MobileVerificationResult;

final class MobileVerificationResultTest extends TestCase
{
    #[Test]
    public function invalidReturnsInvalidResult(): void
    {
        $result = MobileVerificationResult::invalid();

        self::assertFalse($result->isValid);
        self::assertNull($result->expiresAt);
        self::assertNull($result->gracePeriodUntil);
        self::assertSame('', $result->productId);
        self::assertFalse($result->autoRenewing);
    }

    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $expires = new DateTimeImmutable('+30 days');
        $grace = new DateTimeImmutable('+33 days');

        $result = new MobileVerificationResult(
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
}
