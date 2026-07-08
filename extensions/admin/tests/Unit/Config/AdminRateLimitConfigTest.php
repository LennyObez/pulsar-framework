<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Config\AdminRateLimitConfig;

#[CoversClass(AdminRateLimitConfig::class)]
final class AdminRateLimitConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = AdminRateLimitConfig::fromArray([]);

        self::assertSame(120, $config->readLimit);
        self::assertSame(30, $config->writeLimit);
        self::assertSame(5, $config->exportLimit);
        self::assertSame(60, $config->windowSeconds);
    }

    #[Test]
    public function fromArrayWithCustomValues(): void
    {
        $config = AdminRateLimitConfig::fromArray([
            'read_limit' => 200,
            'write_limit' => 50,
            'export_limit' => 10,
            'window_seconds' => 120,
        ]);

        self::assertSame(200, $config->readLimit);
        self::assertSame(50, $config->writeLimit);
        self::assertSame(10, $config->exportLimit);
        self::assertSame(120, $config->windowSeconds);
    }

    #[Test]
    #[DataProvider('partialConfigProvider')]
    public function fromArrayWithPartialValues(string $key, mixed $value, string $property, mixed $expected): void
    {
        $config = AdminRateLimitConfig::fromArray([$key => $value]);

        self::assertSame($expected, $config->$property);
    }

    /**
     * @return iterable<string, array{string, mixed, string, mixed}>
     */
    public static function partialConfigProvider(): iterable
    {
        yield 'only read_limit' => ['read_limit', 999, 'readLimit', 999];
        yield 'only write_limit' => ['write_limit', 1, 'writeLimit', 1];
        yield 'only export_limit' => ['export_limit', 0, 'exportLimit', 0];
        yield 'only window_seconds' => ['window_seconds', 300, 'windowSeconds', 300];
    }

    #[Test]
    public function constructorPropertiesAreReadonly(): void
    {
        $config = new AdminRateLimitConfig(
            readLimit: 100,
            writeLimit: 20,
            exportLimit: 3,
            windowSeconds: 30,
        );

        self::assertSame(100, $config->readLimit);
        self::assertSame(20, $config->writeLimit);
        self::assertSame(3, $config->exportLimit);
        self::assertSame(30, $config->windowSeconds);
    }
}
