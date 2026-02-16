<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\HstsConfig;

#[CoversClass(HstsConfig::class)]
final class HstsConfigTest extends TestCase
{
    #[Test]
    public function fromArrayCreatesConfig(): void
    {
        $config = HstsConfig::fromArray([
            'enabled' => true,
            'max_age' => 63072000,
            'include_sub_domains' => false,
            'preload' => true,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(63072000, $config->maxAge);
        self::assertFalse($config->includeSubDomains);
        self::assertTrue($config->preload);
    }

    #[Test]
    public function toHeaderValueProducesCorrectString(): void
    {
        $config = new HstsConfig(maxAge: 63072000, includeSubDomains: true, preload: false);

        self::assertSame('max-age=63072000; includeSubDomains', $config->toHeaderValue());
    }

    #[Test]
    public function toHeaderValueWithPreload(): void
    {
        $config = new HstsConfig(maxAge: 63072000, includeSubDomains: true, preload: true);

        self::assertSame('max-age=63072000; includeSubDomains; preload', $config->toHeaderValue());
    }

    #[Test]
    public function toHeaderValueWithoutIncludeSubDomains(): void
    {
        $config = new HstsConfig(maxAge: 63072000, includeSubDomains: false, preload: false);

        self::assertSame('max-age=63072000', $config->toHeaderValue());
    }

    #[Test]
    public function defaultsAreReasonable(): void
    {
        $config = new HstsConfig();

        self::assertTrue($config->enabled);
        self::assertSame(63072000, $config->maxAge);
        self::assertTrue($config->includeSubDomains);
        self::assertFalse($config->preload);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = HstsConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame(63072000, $config->maxAge);
        self::assertTrue($config->includeSubDomains);
        self::assertFalse($config->preload);
    }
}
