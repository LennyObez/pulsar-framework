<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\RateLimit;

use DateInterval;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Http\RateLimit\CacheRateLimiter;
use RuntimeException;

use function time;

#[CoversClass(CacheRateLimiter::class)]
final class CacheRateLimiterTest extends TestCase
{
    #[Test]
    public function allowsUpToTheLimitThenBlocksWithRetryAfter(): void
    {
        $limiter = new CacheRateLimiter($this->arrayCache(), maxAttempts: 3, windowSeconds: 60);

        self::assertTrue($limiter->hit('1.2.3.4')->allowed);
        self::assertTrue($limiter->hit('1.2.3.4')->allowed);

        $third = $limiter->hit('1.2.3.4');
        self::assertTrue($third->allowed);
        self::assertSame(0, $third->remaining);

        $fourth = $limiter->hit('1.2.3.4');
        self::assertFalse($fourth->allowed);
        self::assertTrue($fourth->exceeded());
        self::assertGreaterThan(0, $fourth->retryAfter);
    }

    #[Test]
    public function countsPersistAcrossInstancesSharingTheStore(): void
    {
        // Two limiter instances = two FPM workers sharing one cache.
        $cache = $this->arrayCache();
        $workerA = new CacheRateLimiter($cache, maxAttempts: 2, windowSeconds: 60);
        $workerB = new CacheRateLimiter($cache, maxAttempts: 2, windowSeconds: 60);

        self::assertTrue($workerA->hit('user')->allowed);
        self::assertTrue($workerB->hit('user')->allowed);
        self::assertFalse($workerB->hit('user')->allowed);
    }

    #[Test]
    public function startsAFreshWindowWhenThePreviousOneHasExpired(): void
    {
        $cache = $this->arrayCache();
        // Seed an exhausted window that started well outside the 60s window.
        $cache->set('ratelimit.expired', ['c' => 99, 'w' => time() - 1000]);

        $limiter = new CacheRateLimiter($cache, maxAttempts: 3, windowSeconds: 60);
        $result = $limiter->hit('expired');

        self::assertTrue($result->allowed);
        self::assertSame(2, $result->remaining);
    }

    #[Test]
    public function failsOpenWhenTheCacheThrows(): void
    {
        $limiter = new CacheRateLimiter($this->throwingCache(), maxAttempts: 1, windowSeconds: 60);

        // Even far beyond the limit, a broken store never blocks traffic.
        self::assertTrue($limiter->hit('x')->allowed);
        self::assertTrue($limiter->hit('x')->allowed);
        self::assertSame(0, $limiter->attempts('x'));
    }

    #[Test]
    public function sanitizesPsr6ReservedCharactersInKeys(): void
    {
        // A middleware key like "rate_limit:1.2.3.4" contains ':' which PSR-6/16
        // reserve; the limiter must not let it reach the cache verbatim.
        $cache = new class implements CacheInterface {
            /** @var array<string, mixed> */
            public array $store = [];

            public function get(string $key, mixed $default = null): mixed
            {
                $this->assertLegal($key);

                return $this->store[$key] ?? $default;
            }

            public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
            {
                $this->assertLegal($key);
                $this->store[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);

                return true;
            }

            public function clear(): bool
            {
                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                return [];
            }

            public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
            {
                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                return true;
            }

            public function has(string $key): bool
            {
                return isset($this->store[$key]);
            }

            private function assertLegal(string $key): void
            {
                if (preg_match('#[{}()/\\\\@:]#', $key) === 1) {
                    throw new RuntimeException("Illegal PSR-16 key: {$key}");
                }
            }
        };

        $limiter = new CacheRateLimiter($cache, maxAttempts: 5, windowSeconds: 60);

        // Would throw inside the cache if the ':' were not sanitized.
        self::assertTrue($limiter->hit('rate_limit:1.2.3.4')->allowed);
        self::assertNotSame([], $cache->store);
    }

    private function arrayCache(): CacheInterface
    {
        return new class implements CacheInterface {
            /** @var array<string, mixed> */
            private array $store = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[$key] ?? $default;
            }

            public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
            {
                $this->store[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->store = [];

                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                return [];
            }

            public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
            {
                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                return true;
            }

            public function has(string $key): bool
            {
                return isset($this->store[$key]);
            }
        };
    }

    private function throwingCache(): CacheInterface
    {
        return new class implements CacheInterface {
            public function get(string $key, mixed $default = null): mixed
            {
                throw new RuntimeException('cache down');
            }

            public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
            {
                throw new RuntimeException('cache down');
            }

            public function delete(string $key): bool
            {
                throw new RuntimeException('cache down');
            }

            public function clear(): bool
            {
                throw new RuntimeException('cache down');
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                throw new RuntimeException('cache down');
            }

            public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
            {
                throw new RuntimeException('cache down');
            }

            public function deleteMultiple(iterable $keys): bool
            {
                throw new RuntimeException('cache down');
            }

            public function has(string $key): bool
            {
                throw new RuntimeException('cache down');
            }
        };
    }
}
