<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Idempotency;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Idempotency\Exception\IdempotencyException;
use RuntimeException;

#[CoversClass(IdempotencyException::class)]
final class IdempotencyExceptionTest extends TestCase
{
    #[Test]
    public function parameterMismatchContainsKey(): void
    {
        $exception = IdempotencyException::parameterMismatch('key-123');

        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertStringContainsString('key-123', $exception->getMessage());
        self::assertStringContainsString('different parameters', $exception->getMessage());
    }

    #[Test]
    public function concurrentClaimContainsKey(): void
    {
        $exception = IdempotencyException::concurrentClaim('key-456');

        self::assertStringContainsString('key-456', $exception->getMessage());
        self::assertStringContainsString('currently being processed', $exception->getMessage());
    }

    #[Test]
    public function invalidKeyContainsReason(): void
    {
        $exception = IdempotencyException::invalidKey('too long');

        self::assertStringContainsString('too long', $exception->getMessage());
        self::assertStringContainsString('Invalid idempotency key', $exception->getMessage());
    }

    #[Test]
    public function commitFailedContainsKey(): void
    {
        $exception = IdempotencyException::commitFailed('key-789');

        self::assertStringContainsString('key-789', $exception->getMessage());
        self::assertStringContainsString('Failed to commit', $exception->getMessage());
    }

    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = IdempotencyException::invalidKey('test');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }
}
