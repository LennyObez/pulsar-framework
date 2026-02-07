<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\Exception\NotificationException;
use RuntimeException;

#[CoversClass(NotificationException::class)]
final class NotificationExceptionTest extends TestCase
{
    #[Test]
    public function it_creates_delivery_failed(): void
    {
        $previous = new RuntimeException('timeout');
        $e = NotificationException::deliveryFailed('mail', 'user-1', $previous);

        self::assertStringContainsString('mail', $e->getMessage());
        self::assertStringContainsString('user-1', $e->getMessage());
        self::assertStringContainsString('delivery failed', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
    }

    #[Test]
    public function it_creates_delivery_failed_without_previous(): void
    {
        $e = NotificationException::deliveryFailed('sms', 'user-2');

        self::assertStringContainsString('sms', $e->getMessage());
        self::assertNull($e->getPrevious());
    }

    #[Test]
    public function it_creates_channel_not_available(): void
    {
        $e = NotificationException::channelNotAvailable('slack', 'API key missing');

        self::assertStringContainsString('slack', $e->getMessage());
        self::assertStringContainsString('API key missing', $e->getMessage());
    }

    #[Test]
    public function it_creates_rate_limit_exceeded(): void
    {
        $e = NotificationException::rateLimitExceeded('user-3', 'sms', 60);

        self::assertStringContainsString('user-3', $e->getMessage());
        self::assertStringContainsString('sms', $e->getMessage());
        self::assertStringContainsString('60', $e->getMessage());
    }

    #[Test]
    public function it_creates_legal_basis_missing(): void
    {
        $e = NotificationException::legalBasisMissing('MarketingNotification');

        self::assertStringContainsString('MarketingNotification', $e->getMessage());
        self::assertStringContainsString('Legal basis', $e->getMessage());
    }

    #[Test]
    public function it_creates_preference_violation(): void
    {
        $e = NotificationException::preferenceViolation('user-4', 'email');

        self::assertStringContainsString('user-4', $e->getMessage());
        self::assertStringContainsString('email', $e->getMessage());
        self::assertStringContainsString('opted out', $e->getMessage());
    }

    #[Test]
    public function it_extends_runtime_exception(): void
    {
        $e = NotificationException::deliveryFailed('test', 'test');

        self::assertInstanceOf(RuntimeException::class, $e);
    }
}
