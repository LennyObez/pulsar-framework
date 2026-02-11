<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\CacheException;

#[CoversClass(CacheException::class)]
final class CacheExceptionTest extends TestCase
{
    #[Test]
    public function writeFailure(): void
    {
        $e = CacheException::writeFailure('/var/cache/test.php', 'permission denied');

        self::assertStringContainsString('/var/cache/test.php', $e->getMessage());
        self::assertStringContainsString('permission denied', $e->getMessage());
    }

    #[Test]
    public function corruptedCache(): void
    {
        $e = CacheException::corruptedCache('/var/cache/routes.php', 'invalid JSON');

        self::assertStringContainsString('/var/cache/routes.php', $e->getMessage());
        self::assertStringContainsString('invalid JSON', $e->getMessage());
    }

    #[Test]
    public function signatureInvalid(): void
    {
        $e = CacheException::signatureInvalid('/var/cache/data.php');

        self::assertStringContainsString('/var/cache/data.php', $e->getMessage());
        self::assertStringContainsString('HMAC', $e->getMessage());
    }

    #[Test]
    public function staleCache(): void
    {
        $e = CacheException::staleCache('config changed');

        self::assertStringContainsString('config changed', $e->getMessage());
    }

    #[Test]
    public function lockFailed(): void
    {
        $e = CacheException::lockFailed('/var/cache/lock');

        self::assertStringContainsString('/var/cache/lock', $e->getMessage());
    }

    #[Test]
    public function directoryInvalid(): void
    {
        $e = CacheException::directoryInvalid('/var/cache', 'not writable');

        self::assertStringContainsString('/var/cache', $e->getMessage());
        self::assertStringContainsString('not writable', $e->getMessage());
    }
}
