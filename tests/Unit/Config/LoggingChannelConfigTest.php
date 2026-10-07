<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\LoggingChannelConfig;

#[CoversClass(LoggingChannelConfig::class)]
final class LoggingChannelConfigTest extends TestCase
{
    #[Test]
    public function constructorSetsRequiredProperties(): void
    {
        $config = new LoggingChannelConfig(name: 'file', driver: 'file');

        self::assertSame('file', $config->name);
        self::assertSame('file', $config->driver);
        self::assertNull($config->path);
        self::assertNull($config->stream);
    }

    #[Test]
    public function constructorWithAllProperties(): void
    {
        $config = new LoggingChannelConfig(
            name: 'daily',
            driver: 'rotating_file',
            path: '/var/log/app.log',
            stream: 'php://stderr',
        );

        self::assertSame('daily', $config->name);
        self::assertSame('rotating_file', $config->driver);
        self::assertSame('/var/log/app.log', $config->path);
        self::assertSame('php://stderr', $config->stream);
    }

    #[Test]
    public function optionalFieldsDefaultToNull(): void
    {
        $config = new LoggingChannelConfig(name: 'stderr', driver: 'stream');

        self::assertNull($config->path);
        self::assertNull($config->stream);
    }
}
