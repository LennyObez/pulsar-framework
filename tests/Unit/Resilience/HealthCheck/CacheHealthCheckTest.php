<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience\HealthCheck;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Resilience\HealthCheck\CacheHealthCheck;
use Pulsar\Resilience\HealthCheck\HealthStatus;
use RuntimeException;

#[CoversClass(CacheHealthCheck::class)]
final class CacheHealthCheckTest extends TestCase
{
    #[Test]
    public function nameReturnsCache(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $check = new CacheHealthCheck($cache);

        self::assertSame('cache', $check->getName());
    }

    #[Test]
    public function checkReturnsHealthyWhenCacheWorks(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('set')->willReturn(true);
        $cache->method('get')->willReturn('1');
        $cache->method('delete')->willReturn(true);

        $check = new CacheHealthCheck($cache);
        $result = $check->check();

        self::assertSame(HealthStatus::Healthy, $result->status);
        self::assertSame('cache', $result->name);
        self::assertGreaterThanOrEqual(0.0, $result->responseTimeMs);
        self::assertStringContainsString('Cache responded', $result->message);
    }

    #[Test]
    public function checkReturnsUnhealthyWhenCacheThrows(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('set')->willThrowException(new RuntimeException('Connection refused'));

        $check = new CacheHealthCheck($cache);
        $result = $check->check();

        self::assertSame(HealthStatus::Unhealthy, $result->status);
        self::assertStringContainsString('Connection refused', $result->message);
    }

    #[Test]
    public function checkReturnsUnhealthyWhenReadbackMismatches(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('set')->willReturn(true);
        $cache->method('get')->willReturn('wrong-value');
        $cache->method('delete')->willReturn(true);

        $check = new CacheHealthCheck($cache);
        $result = $check->check();

        self::assertSame(HealthStatus::Unhealthy, $result->status);
        self::assertStringContainsString('mismatch', $result->message);
    }

    #[Test]
    public function checkRecordsResponseTimeMs(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('set')->willReturn(true);
        $cache->method('get')->willReturn('1');
        $cache->method('delete')->willReturn(true);

        $check = new CacheHealthCheck($cache);
        $result = $check->check();

        // Response time should be a non-negative float
        self::assertGreaterThanOrEqual(0.0, $result->responseTimeMs);
    }
}
