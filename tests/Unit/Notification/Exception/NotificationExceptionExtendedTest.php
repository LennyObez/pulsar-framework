<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\Exception\NotificationException;
use RuntimeException;

#[CoversClass(NotificationException::class)]
final class NotificationExceptionExtendedTest extends TestCase
{
    #[Test]
    public function deliveryFailedContainsChannelAndNotifiable(): void
    {
        $exception = NotificationException::deliveryFailed('sms', 'user-42');

        self::assertStringContainsString('sms', $exception->getMessage());
        self::assertStringContainsString('user-42', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }

    #[Test]
    public function deliveryFailedPreservesPreviousException(): void
    {
        $previous = new RuntimeException('gateway error');
        $exception = NotificationException::deliveryFailed('mail', 'user-1', $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function channelNotAvailableContainsChannelAndReason(): void
    {
        $exception = NotificationException::channelNotAvailable('push', 'FCM not configured');

        self::assertStringContainsString('push', $exception->getMessage());
        self::assertStringContainsString('FCM not configured', $exception->getMessage());
    }

    #[Test]
    public function rateLimitExceededContainsAllParameters(): void
    {
        $exception = NotificationException::rateLimitExceeded('user-99', 'slack', 10);

        self::assertStringContainsString('user-99', $exception->getMessage());
        self::assertStringContainsString('slack', $exception->getMessage());
        self::assertStringContainsString('10', $exception->getMessage());
    }

    #[Test]
    public function legalBasisMissingContainsNotificationType(): void
    {
        $exception = NotificationException::legalBasisMissing('App\\MarketingNotification');

        self::assertStringContainsString('App\\MarketingNotification', $exception->getMessage());
        self::assertStringContainsString('legal basis', mb_strtolower($exception->getMessage()));
    }

    #[Test]
    public function preferenceViolationContainsNotifiableAndChannel(): void
    {
        $exception = NotificationException::preferenceViolation('user-5', 'mail');

        self::assertStringContainsString('user-5', $exception->getMessage());
        self::assertStringContainsString('mail', $exception->getMessage());
        self::assertStringContainsString('opted out', mb_strtolower($exception->getMessage()));
    }

    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = NotificationException::deliveryFailed('ch', 'uid');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }
}
