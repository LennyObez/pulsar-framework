<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheException as PsrCacheException;
use Psr\SimpleCache\CacheException as PsrSimpleCacheException;
use Pulsar\Cache\Application\Exception\CacheException;
use Pulsar\Cache\Application\Exception\FenceTokenMismatchException;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;
use Pulsar\Cache\Application\Exception\UnsupportedCapabilityException;
use RuntimeException;

#[CoversClass(CacheException::class)]
#[CoversClass(UnsupportedCapabilityException::class)]
#[CoversClass(FenceTokenMismatchException::class)]
#[CoversClass(LockAcquisitionException::class)]
final class CacheExceptionTest extends TestCase
{
    #[Test]
    public function driverErrorContainsDriverAndReason(): void
    {
        $previous = new RuntimeException('connection refused');
        $exception = CacheException::driverError('redis', 'connection refused', $previous);

        self::assertStringContainsString('redis', $exception->getMessage());
        self::assertStringContainsString('connection refused', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function poolNotConfiguredContainsPoolName(): void
    {
        $exception = CacheException::poolNotConfigured('sessions');

        self::assertStringContainsString('sessions', $exception->getMessage());
        self::assertStringContainsString('not configured', $exception->getMessage());
    }

    #[Test]
    public function serializationFailedContainsReason(): void
    {
        $previous = new RuntimeException('json error');
        $exception = CacheException::serializationFailed('json error', $previous);

        self::assertStringContainsString('serialization failed', mb_strtolower($exception->getMessage()));
        self::assertStringContainsString('json error', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function invalidTtlContainsReason(): void
    {
        $exception = CacheException::invalidTtl('negative value');

        self::assertStringContainsString('negative value', $exception->getMessage());
    }

    #[Test]
    public function decryptionFailedContainsPoolAndKey(): void
    {
        $previous = new RuntimeException('bad cipher');
        $exception = CacheException::decryptionFailed('secure-pool', 'user.123', $previous);

        self::assertStringContainsString('secure-pool', $exception->getMessage());
        self::assertStringContainsString('user.123', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function aadMismatchContainsPoolAndKey(): void
    {
        $exception = CacheException::aadMismatch('encrypted', 'session.abc');

        self::assertStringContainsString('encrypted', $exception->getMessage());
        self::assertStringContainsString('session.abc', $exception->getMessage());
    }

    #[Test]
    public function unknownKeyIdContainsPoolAndKeyId(): void
    {
        $exception = CacheException::unknownKeyId('main', 'key-v2');

        self::assertStringContainsString('main', $exception->getMessage());
        self::assertStringContainsString('key-v2', $exception->getMessage());
    }

    #[Test]
    public function cacheExceptionImplementsPsrCacheException(): void
    {
        $exception = CacheException::poolNotConfigured('test');

        self::assertInstanceOf(PsrCacheException::class, $exception);
    }

    #[Test]
    public function cacheExceptionImplementsPsrSimpleCacheException(): void
    {
        $exception = CacheException::poolNotConfigured('test');

        self::assertInstanceOf(PsrSimpleCacheException::class, $exception);
    }

    #[Test]
    public function strictTagsUnsupportedContainsDriverName(): void
    {
        $exception = UnsupportedCapabilityException::strictTagsUnsupported('filesystem');

        self::assertStringContainsString('filesystem', $exception->getMessage());
        self::assertStringContainsString('strict tag', $exception->getMessage());
    }

    #[Test]
    public function fencingUnsupportedContainsDriverName(): void
    {
        $exception = UnsupportedCapabilityException::fencingUnsupported('array');

        self::assertStringContainsString('array', $exception->getMessage());
        self::assertStringContainsString('fencing', $exception->getMessage());
    }

    #[Test]
    public function unsupportedCapabilityImplementsPsrInterfaces(): void
    {
        $exception = UnsupportedCapabilityException::strictTagsUnsupported('test');

        self::assertInstanceOf(PsrCacheException::class, $exception);
        self::assertInstanceOf(PsrSimpleCacheException::class, $exception);
    }

    #[Test]
    public function tokenMismatchContainsResourceAndTokens(): void
    {
        $exception = FenceTokenMismatchException::tokenMismatch('lock-resource', 'token-a', 'token-b');

        self::assertStringContainsString('lock-resource', $exception->getMessage());
        self::assertStringContainsString('token-a', $exception->getMessage());
        self::assertStringContainsString('token-b', $exception->getMessage());
    }

    #[Test]
    public function tokenExpiredContainsResource(): void
    {
        $exception = FenceTokenMismatchException::tokenExpired('lock-resource');

        self::assertStringContainsString('lock-resource', $exception->getMessage());
        self::assertStringContainsString('expired', mb_strtolower($exception->getMessage()));
    }

    #[Test]
    public function fenceTokenMismatchImplementsPsrInterfaces(): void
    {
        $exception = FenceTokenMismatchException::tokenExpired('test');

        self::assertInstanceOf(PsrCacheException::class, $exception);
        self::assertInstanceOf(PsrSimpleCacheException::class, $exception);
    }

    #[Test]
    public function lockTimeoutContainsResourceAndTimeout(): void
    {
        $exception = LockAcquisitionException::timeout('my-resource', 5000);

        self::assertStringContainsString('my-resource', $exception->getMessage());
        self::assertStringContainsString('5000', $exception->getMessage());
    }

    #[Test]
    public function lockUnavailableContainsResourceAndReason(): void
    {
        $exception = LockAcquisitionException::unavailable('my-resource', 'driver down');

        self::assertStringContainsString('my-resource', $exception->getMessage());
        self::assertStringContainsString('driver down', $exception->getMessage());
    }

    #[Test]
    public function lockAcquisitionImplementsPsrCacheException(): void
    {
        $exception = LockAcquisitionException::timeout('test', 100);

        self::assertInstanceOf(PsrCacheException::class, $exception);
    }

    #[Test]
    public function lockAcquisitionImplementsPsrSimpleCacheException(): void
    {
        $exception = LockAcquisitionException::timeout('test', 100);

        self::assertInstanceOf(PsrSimpleCacheException::class, $exception);
    }

    #[Test]
    public function encryptionUnavailableContainsPoolName(): void
    {
        $exception = CacheException::encryptionUnavailable('secure-pool');

        self::assertStringContainsString('secure-pool', $exception->getMessage());
        self::assertStringContainsString('encryption', mb_strtolower($exception->getMessage()));
    }

    #[Test]
    public function databaseConnectionRequiredContainsDriverName(): void
    {
        $exception = CacheException::databaseConnectionRequired('database');

        self::assertStringContainsString('database', $exception->getMessage());
        self::assertStringContainsString('connection', mb_strtolower($exception->getMessage()));
    }

    #[Test]
    public function atomicIncrementOnEncryptedPoolContainsMessage(): void
    {
        $exception = UnsupportedCapabilityException::atomicIncrementOnEncryptedPool();

        self::assertStringContainsString('increment/decrement', mb_strtolower($exception->getMessage()));
        self::assertStringContainsString('encrypted', mb_strtolower($exception->getMessage()));
    }
}
