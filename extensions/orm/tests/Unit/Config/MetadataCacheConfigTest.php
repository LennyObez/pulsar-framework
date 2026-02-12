<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Config\MetadataCacheConfig;

final class MetadataCacheConfigTest extends TestCase
{
    #[Test]
    public function constructionWithExplicitValues(): void
    {
        $config = new MetadataCacheConfig(driver: 'file', path: '/tmp/cache');

        self::assertSame('file', $config->driver);
        self::assertSame('/tmp/cache', $config->path);
    }

    #[Test]
    public function fromArrayWithAllKeys(): void
    {
        $config = MetadataCacheConfig::fromArray([
            'driver' => 'redis',
            'path' => '/var/cache/metadata',
        ]);

        self::assertSame('redis', $config->driver);
        self::assertSame('/var/cache/metadata', $config->path);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = MetadataCacheConfig::fromArray([]);

        self::assertSame('array', $config->driver);
        self::assertSame('', $config->path);
    }
}
