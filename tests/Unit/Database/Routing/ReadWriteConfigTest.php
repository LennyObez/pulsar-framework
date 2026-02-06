<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Routing\ReadWriteConfig;

#[CoversClass(ReadWriteConfig::class)]
final class ReadWriteConfigTest extends TestCase
{
    public function test_from_array_with_defaults(): void
    {
        $config = ReadWriteConfig::fromArray([]);

        self::assertSame([], $config->readHosts);
        self::assertSame('127.0.0.1', $config->writeHost);
        self::assertSame('request', $config->stickyDuration);
        self::assertFalse($config->enabled);
    }

    public function test_from_array_with_custom_values(): void
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

    public function test_from_array_with_request_scoped_sticky_duration(): void
    {
        $config = ReadWriteConfig::fromArray([
            'sticky_duration' => 'request',
        ]);

        self::assertSame('request', $config->stickyDuration);
    }

    public function test_constructor_sets_properties(): void
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
