<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Idempotency;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Idempotency\Exception\IdempotencyErrorKind;
use Pulsar\Idempotency\Exception\IdempotencyException;
use RuntimeException;
use RuntimeException as PreviousException;

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

    /**
     * Every factory must tag the exception with the matching kind so HTTP
     * layers can map concurrent → 409 vs other → 4xx/500 without parsing
     * exception messages.
     */
    #[Test]
    public function parameterMismatchTagsKind(): void
    {
        self::assertSame(
            IdempotencyErrorKind::ParameterMismatch,
            IdempotencyException::parameterMismatch('key')->kind,
        );
    }

    #[Test]
    public function concurrentClaimTagsKind(): void
    {
        self::assertSame(
            IdempotencyErrorKind::ConcurrentClaim,
            IdempotencyException::concurrentClaim('key')->kind,
        );
    }

    #[Test]
    public function invalidKeyTagsKind(): void
    {
        self::assertSame(
            IdempotencyErrorKind::InvalidKey,
            IdempotencyException::invalidKey('reason')->kind,
        );
    }

    #[Test]
    public function commitFailedTagsKind(): void
    {
        self::assertSame(
            IdempotencyErrorKind::CommitFailed,
            IdempotencyException::commitFailed('key')->kind,
        );
    }

    #[Test]
    public function tamperedPayloadTagsKind(): void
    {
        self::assertSame(
            IdempotencyErrorKind::TamperedPayload,
            IdempotencyException::tamperedPayload('key', 'mac mismatch')->kind,
        );
    }

    #[Test]
    public function serializationFailedTagsKindAndChainsPrevious(): void
    {
        $previous = new PreviousException('decode failed');
        $exception = IdempotencyException::serializationFailed('key', $previous);

        self::assertSame(IdempotencyErrorKind::SerializationFailed, $exception->kind);
        self::assertSame($previous, $exception->getPrevious());
    }
}
