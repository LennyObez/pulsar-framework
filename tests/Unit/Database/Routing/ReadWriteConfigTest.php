<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Routing\ReadWriteConfig;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ReadWriteConfig::class)]
final class ReadWriteConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = ReadWriteConfig::fromArray([]);

        self::assertSame([], $config->readHosts);
        self::assertSame('127.0.0.1', $config->writeHost);
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
    public function constructorSetsProperties(): void
    {
        $config = new ReadWriteConfig(
            readHosts: ['replica-a'],
            writeHost: 'primary',
            stickyDuration: 3000,
            enabled: true,
        );

        self::assertSame(['replica-a'], $config->readHosts);
        self::assertSame('primary', $config->writeHost);
        self::assertSame(3000, $config->stickyDuration);
        self::assertTrue($config->enabled);
    }
}
