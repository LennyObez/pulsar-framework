<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\EnvironmentMode;

#[CoversNothing]
final class EnvironmentModeTest extends TestCase
{
    #[Test]
    #[DataProvider('debugDefaultProvider')]
    public function isDebugByDefaultReturnsCorrectValue(EnvironmentMode $mode, bool $expected): void
    {
        self::assertSame($expected, $mode->isDebugByDefault());
    }

    /**
     * @return iterable<string, array{EnvironmentMode, bool}>
     */
    public static function debugDefaultProvider(): iterable
    {
        yield 'local is debug' => [EnvironmentMode::Local, true];
        yield 'staging is not debug' => [EnvironmentMode::Staging, false];
        yield 'production is not debug' => [EnvironmentMode::Production, false];
    }

    #[Test]
    public function backingValues(): void
    {
        self::assertSame('local', EnvironmentMode::Local->value);
        self::assertSame('staging', EnvironmentMode::Staging->value);
        self::assertSame('production', EnvironmentMode::Production->value);
    }

    #[Test]
    public function allCases(): void
    {
        self::assertCount(3, EnvironmentMode::cases());
    }
}
