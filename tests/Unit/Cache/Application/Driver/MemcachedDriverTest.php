<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Driver;

use Memcached;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\Driver\MemcachedDriver;

#[CoversClass(MemcachedDriver::class)]
#[RequiresPhpExtension('memcached')]
final class MemcachedDriverTest extends TestCase
{
    use CacheDriverContractTrait;

    private Memcached $memcached;

    protected function setUp(): void
    {
        $this->memcached = new Memcached();
        $this->memcached->addServer('127.0.0.1', 11211);

        // Verify connectivity by performing a simple operation
        $this->memcached->getVersion();

        if ($this->memcached->getResultCode() === Memcached::RES_CONNECTION_SOCKET_CREATE_FAILURE
            || $this->memcached->getResultCode() === Memcached::RES_FAILURE) {
            self::markTestSkipped('Memcached server not available at 127.0.0.1:11211');
        }

        $this->memcached->flush();
        $this->driver = $this->createDriver();
    }

    protected function tearDown(): void
    {
        if (isset($this->memcached)) {
            $this->memcached->flush();
            $this->memcached->quit();
        }
    }

    protected function createDriver(): CacheDriverInterface
    {
        return new MemcachedDriver($this->memcached);
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
    public function capabilitiesSupportsBinaryAndAtomicIncrement(): void
    {
        $capabilities = $this->driver->capabilities();

        self::assertFalse($capabilities->supportsTagsStrict);
        self::assertFalse($capabilities->supportsLocksFencing);
        self::assertTrue($capabilities->supportsBinary);
        self::assertTrue($capabilities->supportsAtomicIncrement);
    }

    #[Test]
    public function nameReturnsMemcached(): void
    {
        self::assertSame('memcached', $this->driver->name());
    }
}
