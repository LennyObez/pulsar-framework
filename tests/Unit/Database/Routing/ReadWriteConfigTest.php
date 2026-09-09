<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Routing\ReadWriteConfig;

#[CoversClass(ReadWriteConfig::class)]
final class ReadWriteConfigTest extends TestCase
{
    /**
     * An omitted `write_host` means the default connection exactly as `connections`
     * configures it, and is therefore empty.
     *
     * It used to default to `'127.0.0.1'`, which was harmless only for as long as
     * nothing read the value. Now that `write_host` selects the connection writes go
     * to, that default would silently repoint the primary of every deployment that had
     * simply left the key out of `config/database.php`.
     */
    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = ReadWriteConfig::fromArray([]);

        self::assertSame([], $config->readHosts);
        self::assertSame('', $config->writeHost);
        self::assertSame('request', $config->stickyDuration);
        self::assertFalse($config->enabled);
    }

    #[Test]
    public function fromArrayWithCustomValues(): void
    {
        $config = ReadWriteConfig::fromArray([
            'read_hosts' => ['replica-1', 'replica-2'],
            'write_host' => 'primary.db.local',
            'sticky_duration' => 5000,
            'enabled' => true,
        ]);

        self::assertSame(['replica-1', 'replica-2'], $config->readHosts);
        self::assertSame('primary.db.local', $config->writeHost);
        self::assertSame(5000, $config->stickyDuration);
        self::assertTrue($config->enabled);
    }

    #[Test]
    public function fromArrayWithRequestScopedStickyDuration(): void
    {
        $config = ReadWriteConfig::fromArray([
            'sticky_duration' => 'request',
        ]);

        self::assertSame('request', $config->stickyDuration);
    }

    #[Test]
    public function constructorDefaultsToDisabled(): void
    {
        $config = new ReadWriteConfig();

        self::assertSame([], $config->readHosts);
        self::assertSame('', $config->writeHost);
        self::assertSame('request', $config->stickyDuration);
        self::assertFalse($config->enabled);
    }

    #[Test]
    public function fromArrayCastsEnabledToBoolean(): void
    {
        $config = ReadWriteConfig::fromArray(['enabled' => 1]);

        self::assertTrue($config->enabled);

        $config2 = ReadWriteConfig::fromArray(['enabled' => 0]);

        self::assertFalse($config2->enabled);
    }
}
