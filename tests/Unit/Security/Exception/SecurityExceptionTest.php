<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Exception\SecurityException;
use RuntimeException;

#[CoversClass(SecurityException::class)]
final class SecurityExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = SecurityException::sessionNotStarted();

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function csrfTokenMissing(): void
    {
        $e = SecurityException::csrfTokenMissing();

        self::assertSame('CSRF token is missing from the request', $e->getMessage());
    }

    #[Test]
    public function csrfTokenInvalid(): void
    {
        $e = SecurityException::csrfTokenInvalid();

        self::assertSame('CSRF token is invalid', $e->getMessage());
    }

    #[Test]
    public function sessionNotStarted(): void
    {
        $e = SecurityException::sessionNotStarted();

        self::assertSame('Session has not been started', $e->getMessage());
    }

    #[Test]
    public function sessionStartFailed(): void
    {
        $e = SecurityException::sessionStartFailed();

        self::assertSame('Failed to start session', $e->getMessage());
    }

    #[Test]
    public function masterKeyMissing(): void
    {
        $e = SecurityException::masterKeyMissing();

        self::assertSame('PULSAR_MASTER_KEY environment variable is not set', $e->getMessage());
    }

    #[Test]
    public function masterKeyInvalid(): void
    {
        $e = SecurityException::masterKeyInvalid('too short');

        self::assertSame('Invalid master key: too short', $e->getMessage());
    }

    #[Test]
    public function encryptionFailed(): void
    {
        $e = SecurityException::encryptionFailed('bad key');

        self::assertSame('Encryption failed: bad key', $e->getMessage());
    }

    #[Test]
    public function decryptionFailed(): void
    {
        $e = SecurityException::decryptionFailed();

        self::assertSame('Decryption failed: ciphertext is invalid or has been tampered with', $e->getMessage());
    }

    #[Test]
    public function auditIntegrityViolation(): void
    {
        $e = SecurityException::auditIntegrityViolation('entry-42');

        self::assertSame('Audit log integrity violation for entry "entry-42"', $e->getMessage());
    }

    #[Test]
    public function auditWriteFailed(): void
    {
        $e = SecurityException::auditWriteFailed('disk full');

        self::assertSame('Failed to write audit entry: disk full', $e->getMessage());
    }

    #[Test]
    public function serializationForbidden(): void
    {
        $e = SecurityException::serializationForbidden('MasterKey');

        self::assertSame('Serialization of MasterKey is forbidden: key material must not leave process memory', $e->getMessage());
    }

    #[Test]
    public function sessionValidationFailed(): void
    {
        $e = SecurityException::sessionValidationFailed('fingerprint');

        self::assertSame('Session validation failed: fingerprint', $e->getMessage());
    }

    #[Test]
    public function sessionConcurrencyExceeded(): void
    {
        $e = SecurityException::sessionConcurrencyExceeded(3);

        self::assertSame('Concurrent session limit exceeded: maximum 3 active sessions allowed', $e->getMessage());
    }

    #[Test]
    public function sessionHandlerNotSupported(): void
    {
        $e = SecurityException::sessionHandlerNotSupported('session listing', 'file');

        self::assertSame('Session handler "file" does not support session listing', $e->getMessage());
    }

    #[Test]
    public function sessionEncryptionFailed(): void
    {
        $e = SecurityException::sessionEncryptionFailed('bad nonce');

        self::assertSame('Session encryption failed: bad nonce', $e->getMessage());
    }

    #[Test]
    public function sessionPayloadTooLarge(): void
    {
        $e = SecurityException::sessionPayloadTooLarge(5000, 4096);

        self::assertSame('Session payload size 5000 bytes exceeds maximum of 4096 bytes', $e->getMessage());
    }
}
