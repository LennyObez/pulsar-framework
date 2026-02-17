<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Cache\Application\CacheManagerInterface;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\Lock\LockInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;

#[CoversClass(CacheManagerInterface::class)]
final class CacheManagerInterfaceTest extends TestCase
{
    #[Test]
    public function poolReturnsDefaultWhenNullName(): void
    {
        $pool = $this->createStub(CacheItemPoolInterface::class);

        $manager = $this->createTestManager($pool);

        self::assertSame($pool, $manager->pool());
        self::assertSame($pool, $manager->pool(null));
    }

    #[Test]
    public function poolResolvesNamedPool(): void
    {
        $default = $this->createStub(CacheItemPoolInterface::class);
        $sessions = $this->createStub(CacheItemPoolInterface::class);

        $manager = $this->createTestManager($default, $sessions);

        self::assertSame($sessions, $manager->pool('sessions'));
        self::assertSame($default, $manager->pool());
        self::assertNotSame($default, $sessions);
    }

    private function createTestManager(
        CacheItemPoolInterface $default,
        ?CacheItemPoolInterface $sessions = null,
    ): CacheManagerInterface {
        $simple = $this->createStub(CacheInterface::class);
        $tagged = $this->createStub(TaggedCacheInterface::class);
        $lock = $this->createStub(LockInterface::class);
        $driver = $this->createStub(CacheDriverInterface::class);

        return new class ($default, $sessions, $simple, $tagged, $lock, $driver) implements CacheManagerInterface {
            public function __construct(
                private readonly CacheItemPoolInterface $default,
                private readonly ?CacheItemPoolInterface $sessions,
                private readonly CacheInterface $simple,
                private readonly TaggedCacheInterface $tagged,
                private readonly LockInterface $lock,
                private readonly CacheDriverInterface $driver,
            ) {}

            public function pool(?string $name = null): CacheItemPoolInterface
            {
                if ($name === 'sessions' && $this->sessions !== null) {
                    return $this->sessions;
                }

                return $this->default;
            }

            public function simple(?string $name = null): CacheInterface
            {
                return $this->simple;
            }

            public function tagged(?string $name = null): TaggedCacheInterface
            {
                return $this->tagged;
            }

            public function lock(?string $name = null): LockInterface
            {
                return $this->lock;
            }

            public function driver(?string $name = null): CacheDriverInterface
            {
                return $this->driver;
            }
        };
    }
}
