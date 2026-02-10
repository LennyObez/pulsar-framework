<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\MailEncryptionPolicy;
use Pulsar\Mail\Exception\MailException;
use RuntimeException;

#[CoversClass(MailException::class)]
final class MailExceptionTest extends TestCase
{
    #[Test]
    public function it_creates_send_failed(): void
    {
        $previous = new RuntimeException('timeout');
        $e = MailException::sendFailed('Connection timed out', $previous);

        self::assertStringContainsString('Mail send failed', $e->getMessage());
        self::assertStringContainsString('Connection timed out', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
    }

    #[Test]
    public function it_creates_send_failed_without_previous(): void
    {
        $e = MailException::sendFailed('Generic failure');

        self::assertStringContainsString('Generic failure', $e->getMessage());
        self::assertNull($e->getPrevious());
    }

    #[Test]
    public function it_creates_driver_error(): void
    {
        $e = MailException::driverError('smtp', 'Authentication failed');

        self::assertStringContainsString('smtp', $e->getMessage());
        self::assertStringContainsString('Authentication failed', $e->getMessage());
    }

    #[Test]
    public function it_creates_invalid_recipient(): void
    {
        $e = MailException::invalidRecipient('bad@', 'Invalid format');

        self::assertStringContainsString('bad@', $e->getMessage());
        self::assertStringContainsString('Invalid format', $e->getMessage());
    }

    #[Test]
    public function it_creates_encryption_unavailable(): void
    {
        $e = MailException::encryptionUnavailable('user@test.com', MailEncryptionPolicy::Require);

        self::assertStringContainsString('user@test.com', $e->getMessage());
        self::assertStringContainsString('require', $e->getMessage());
    }

    #[Test]
    public function it_creates_encryption_fallback(): void
    {
        $e = MailException::encryptionFallback('user@test.com', MailEncryptionPolicy::Prefer);

        self::assertStringContainsString('user@test.com', $e->getMessage());
        self::assertStringContainsString('prefer', $e->getMessage());
        self::assertStringContainsString('fallback', $e->getMessage());
    }

    #[Test]
    public function it_creates_transport_not_configured(): void
    {
        $e = MailException::transportNotConfigured('custom');

        self::assertStringContainsString('custom', $e->getMessage());
        self::assertStringContainsString('not configured', $e->getMessage());
    }

    #[Test]
    public function it_extends_runtime_exception(): void
    {
        $e = MailException::sendFailed('test');

        self::assertInstanceOf(RuntimeException::class, $e);
    }
}
