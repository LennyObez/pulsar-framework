<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Driver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Driver\ApcuDriver;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;

use function apcu_clear_cache;
use function ini_get;

#[CoversClass(ApcuDriver::class)]
#[RequiresPhpExtension('apcu')]
final class ApcuDriverTest extends TestCase
{
    use CacheDriverContractTrait;

    protected function setUp(): void
    {
        if (!ini_get('apc.enable_cli')) {
            self::markTestSkipped('APCu CLI mode is not enabled (set apc.enable_cli=1)');
        }

        apcu_clear_cache();
        $this->driver = $this->createDriver();
    }

    protected function tearDown(): void
    {
        if (ini_get('apc.enable_cli')) {
            apcu_clear_cache();
        }
    }

    protected function createDriver(): CacheDriverInterface
    {
        return new ApcuDriver();
    }

    #[Test]
    public function incrementInitializesToStep(): void
    {
        $result = $this->driver->increment('counter', 3);

        self::assertSame(3, $result);
    }

    #[Test]
    public function decrementInitializesToNegativeStep(): void
    {
        $result = $this->driver->decrement('counter', 5);

        self::assertSame(-5, $result);
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
    public function nameReturnsApcu(): void
    {
        self::assertSame('apcu', $this->driver->name());
    }
}
