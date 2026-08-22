<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Consent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\Consent\NotificationClassification;
use Pulsar\Notification\Consent\NotificationType;

#[CoversClass(NotificationType::class)]
final class NotificationTypeTest extends TestCase
{
    #[Test]
    public function transactionalClassification(): void
    {
        $attr = new NotificationType(NotificationClassification::Transactional);

        self::assertSame(NotificationClassification::Transactional, $attr->classification);
    }

    #[Test]
    public function marketingClassification(): void
    {
        $attr = new NotificationType(NotificationClassification::Marketing);

        self::assertSame(NotificationClassification::Marketing, $attr->classification);
    }
}
