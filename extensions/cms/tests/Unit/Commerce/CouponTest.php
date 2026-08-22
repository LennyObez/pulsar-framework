<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\Coupon;

#[CoversClass(Coupon::class)]
final class CouponTest extends TestCase
{
    #[Test]
    public function multi_use_coupon_is_always_available(): void
    {
        $coupon = new Coupon(
            id: 'coupon-1',
            promotionId: 'promo-1',
            code: 'SAVE10',
            isSingleUse: false,
            usedAt: new DateTimeImmutable(),
            usedBy: 'user-1',
        );

        self::assertTrue($coupon->isAvailable());
    }

    #[Test]
    public function single_use_coupon_available_when_unused(): void
    {
        $coupon = new Coupon(
            id: 'coupon-1',
            promotionId: 'promo-1',
            code: 'SAVE10',
            isSingleUse: true,
            usedAt: null,
            usedBy: null,
        );

        self::assertTrue($coupon->isAvailable());
    }

    #[Test]
    public function single_use_coupon_unavailable_when_used(): void
    {
        $coupon = new Coupon(
            id: 'coupon-1',
            promotionId: 'promo-1',
            code: 'SAVE10',
            isSingleUse: true,
            usedAt: new DateTimeImmutable(),
            usedBy: 'user-1',
        );

        self::assertFalse($coupon->isAvailable());
    }
}
