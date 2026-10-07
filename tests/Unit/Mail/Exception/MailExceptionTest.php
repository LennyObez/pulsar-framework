<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Exception;

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
    public function sendFailed(): void
    {
        $exception = MailException::sendFailed('connection refused');

        self::assertInstanceOf(MailException::class, $exception);
        self::assertStringContainsString('Mail send failed', $exception->getMessage());
        self::assertStringContainsString('connection refused', $exception->getMessage());
    }

    #[Test]
    public function sendFailedWithPrevious(): void
    {
        $previous = new RuntimeException('network error');
        $exception = MailException::sendFailed('timeout', $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function driverError(): void
    {
        $exception = MailException::driverError('smtp', 'auth failed');

        self::assertStringContainsString('smtp', $exception->getMessage());
        self::assertStringContainsString('auth failed', $exception->getMessage());
    }

    #[Test]
    public function driverErrorWithPrevious(): void
    {
        $previous = new RuntimeException('inner');
        $exception = MailException::driverError('mailgun', 'rate limited', $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function invalidRecipient(): void
    {
        $exception = MailException::invalidRecipient('bad@', 'invalid format');

        self::assertStringContainsString('bad@', $exception->getMessage());
        self::assertStringContainsString('invalid format', $exception->getMessage());
    }

    #[Test]
    public function encryptionUnavailable(): void
    {
        $exception = MailException::encryptionUnavailable('user@example.com', MailEncryptionPolicy::Require);

        self::assertStringContainsString('user@example.com', $exception->getMessage());
        self::assertStringContainsString('require', $exception->getMessage());
    }

    #[Test]
    public function encryptionFallback(): void
    {
        $exception = MailException::encryptionFallback('user@example.com', MailEncryptionPolicy::Prefer);

        self::assertStringContainsString('user@example.com', $exception->getMessage());
        self::assertStringContainsString('fallback', $exception->getMessage());
    }

    #[Test]
    public function transportNotConfigured(): void
    {
        $exception = MailException::transportNotConfigured('ses');

        self::assertStringContainsString('ses', $exception->getMessage());
        self::assertStringContainsString('not configured', $exception->getMessage());
    }
}
