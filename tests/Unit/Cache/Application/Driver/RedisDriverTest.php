<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Driver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\Driver\RedisDriver;
use Redis;
use RedisException;

#[CoversClass(RedisDriver::class)]
#[RequiresPhpExtension('redis')]
final class RedisDriverTest extends TestCase
{
    use CacheDriverContractTrait;

    private Redis $redis;

    protected function setUp(): void
    {
        $this->redis = new Redis();

        try {
            $this->redis->connect('127.0.0.1', 6379, 1.0);
            $this->redis->flushDB();
        } catch (RedisException) {
            self::markTestSkipped('Redis server not available at 127.0.0.1:6379');
        }

        $this->driver = $this->createDriver();
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            try {
                $this->redis->flushDB();
                $this->redis->close();
            } catch (RedisException) {
                // ignore cleanup errors
            }
        }
    }

    protected function createDriver(): CacheDriverInterface
    {
        return new RedisDriver($this->redis);
    }

    #[Test]
    public function incrementInitializesToStep(): void
    {
        $result = $this->driver->increment('counter', 3);

        self::assertSame(3, $result);
    }

    #[Test]
    public function decrementFromExistingValue(): void
    {
        $this->driver->set('counter', '10', null);

        $result = $this->driver->decrement('counter', 3);

        self::assertSame(7, $result);
    }

    #[Test]
    public function capabilitiesAllTrue(): void
    {
        $capabilities = $this->driver->capabilities();

        self::assertTrue($capabilities->supportsTagsStrict);
        self::assertTrue($capabilities->supportsLocksFencing);
        self::assertTrue($capabilities->supportsBinary);
        self::assertTrue($capabilities->supportsAtomicIncrement);
    }

    #[Test]
    public function nameReturnsRedis(): void
    {
        self::assertSame('redis', $this->driver->name());
    }
}
