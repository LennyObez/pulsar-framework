<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\PaymentStatus;

#[CoversNothing]
final class PaymentStatusTest extends TestCase
{
    #[Test]
    public function isPaid_returns_true_only_for_paid(): void
    {
        self::assertTrue(PaymentStatus::Paid->isPaid());
        self::assertFalse(PaymentStatus::Pending->isPaid());
        self::assertFalse(PaymentStatus::Failed->isPaid());
        self::assertFalse(PaymentStatus::Refunded->isPaid());
        self::assertFalse(PaymentStatus::PartiallyRefunded->isPaid());
    }

    #[Test]
    public function label_returns_human_readable_names(): void
    {
        self::assertSame('Pending', PaymentStatus::Pending->label());
        self::assertSame('Paid', PaymentStatus::Paid->label());
        self::assertSame('Failed', PaymentStatus::Failed->label());
        self::assertSame('Refunded', PaymentStatus::Refunded->label());
        self::assertSame('Partially Refunded', PaymentStatus::PartiallyRefunded->label());
    }
}
