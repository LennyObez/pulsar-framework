<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\FrameworkCacheInterface;
use Pulsar\Config\ConfigRepository;

use function count;

#[CoversClass(FrameworkCacheInterface::class)]
final class FrameworkCacheInterfaceTest extends TestCase
{
    #[Test]
    public function implementationReportsCacheWarmed(): void
    {
        $cache = new class implements FrameworkCacheInterface {
            private bool $warmed = false;

            public function warm(
                ConfigRepository $repository,
                array $routes,
                array $containerHints,
                string $appEnv,
                bool $strict,
            ): array {
                $this->warmed = true;

                return [
                    'configCached' => true,
                    'routesCached' => count($routes),
                    'routesSkipped' => 0,
                    'skippedRoutes' => [],
                    'containerCached' => true,
                ];
            }

            public function clear(): void
            {
                $this->warmed = false;
            }

            public function isWarm(): bool
            {
                return $this->warmed;
            }

            public function load(string $configPath): ?array
            {
                return null;
            }

            public function cachePath(): string
            {
                return '/tmp/cache';
            }

            public function computeInvalidationKey(string $configPath): string
            {
                return hash('xxh128', $configPath);
            }
        };

        self::assertFalse($cache->isWarm());

        $repository = new ConfigRepository();
        $result = $cache->warm($repository, [], [], 'production', true);

        self::assertTrue($cache->isWarm());
        self::assertTrue($result['configCached']);
        self::assertSame(0, $result['routesCached']);
        self::assertTrue($result['containerCached']);
    }

    #[Test]
    public function clearResetsWarmState(): void
    {
        $cache = new class implements FrameworkCacheInterface {
            private bool $warmed = true;

            public function warm(ConfigRepository $repository, array $routes, array $containerHints, string $appEnv, bool $strict): array
            {
                return ['configCached' => false, 'routesCached' => 0, 'routesSkipped' => 0, 'skippedRoutes' => [], 'containerCached' => false];
            }

            public function clear(): void
            {
                $this->warmed = false;
            }

            public function isWarm(): bool
            {
                return $this->warmed;
            }

            public function load(string $configPath): ?array
            {
                return null;
            }

            public function cachePath(): string
            {
                return '/tmp/cache';
            }

            public function computeInvalidationKey(string $configPath): string
            {
                return 'key';
            }
        };

        self::assertTrue($cache->isWarm());
        $cache->clear();
        self::assertFalse($cache->isWarm());
    }

    #[Test]
    public function loadReturnsNullWhenCacheMissing(): void
    {
        $cache = new class implements FrameworkCacheInterface {
            public function warm(ConfigRepository $repository, array $routes, array $containerHints, string $appEnv, bool $strict): array
            {
                return ['configCached' => false, 'routesCached' => 0, 'routesSkipped' => 0, 'skippedRoutes' => [], 'containerCached' => false];
            }

            public function clear(): void {}
            public function isWarm(): bool
            {
                return false;
            }

            public function load(string $configPath): ?array
            {
                return null;
            }

            public function cachePath(): string
            {
                return '/tmp/test-cache';
            }

            public function computeInvalidationKey(string $configPath): string
            {
                return hash('xxh128', $configPath);
            }
        };

        self::assertNull($cache->load('/app/config'));
    }

    #[Test]
    public function cachePathReturnsString(): void
    {
        $cache = new class implements FrameworkCacheInterface {
            public function warm(ConfigRepository $repository, array $routes, array $containerHints, string $appEnv, bool $strict): array
            {
                return ['configCached' => false, 'routesCached' => 0, 'routesSkipped' => 0, 'skippedRoutes' => [], 'containerCached' => false];
            }

            public function clear(): void {}
            public function isWarm(): bool
            {
                return false;
            }
            public function load(string $configPath): ?array
            {
                return null;
            }

            public function cachePath(): string
            {
                return '/var/app/cache';
            }

            public function computeInvalidationKey(string $configPath): string
            {
                return 'inv-key';
            }
        };

        self::assertSame('/var/app/cache', $cache->cachePath());
    }

    #[Test]
    public function computeInvalidationKeyIsDeterministic(): void
    {
        $cache = new class implements FrameworkCacheInterface {
            public function warm(ConfigRepository $repository, array $routes, array $containerHints, string $appEnv, bool $strict): array
            {
                return ['configCached' => false, 'routesCached' => 0, 'routesSkipped' => 0, 'skippedRoutes' => [], 'containerCached' => false];
            }

            public function clear(): void {}
            public function isWarm(): bool
            {
                return false;
            }
            public function load(string $configPath): ?array
            {
                return null;
            }
            public function cachePath(): string
            {
                return '/tmp';
            }

            public function computeInvalidationKey(string $configPath): string
            {
                return hash('xxh128', $configPath);
            }
        };

        $key1 = $cache->computeInvalidationKey('/app/config');
        $key2 = $cache->computeInvalidationKey('/app/config');

        self::assertSame($key1, $key2);
    }
}
