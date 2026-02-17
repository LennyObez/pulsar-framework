<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Audit\DeliveryStatus;

#[CoversClass(DeliveryStatus::class)]
final class DeliveryStatusTest extends TestCase
{
    #[Test]
    public function it_has_expected_values(): void
    {
        self::assertSame('pending', DeliveryStatus::Pending->value);
        self::assertSame('sent', DeliveryStatus::Sent->value);
        self::assertSame('delivered', DeliveryStatus::Delivered->value);
        self::assertSame('bounced', DeliveryStatus::Bounced->value);
        self::assertSame('failed', DeliveryStatus::Failed->value);
    }

    #[Test]
    public function it_has_five_cases(): void
    {
        self::assertCount(5, DeliveryStatus::cases());
    }

    #[Test]
    public function it_resolves_from_string(): void
    {
        self::assertSame(DeliveryStatus::Delivered, DeliveryStatus::from('delivered'));
        self::assertSame(DeliveryStatus::Bounced, DeliveryStatus::from('bounced'));
    }
}
