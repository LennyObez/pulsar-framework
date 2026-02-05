<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Verbosity;

#[CoversClass(Verbosity::class)]
final class VerbosityTest extends TestCase
{
    #[Test]
    public function values(): void
    {
        self::assertSame(0, Verbosity::Quiet->value);
        self::assertSame(1, Verbosity::Normal->value);
        self::assertSame(2, Verbosity::Verbose->value);
        self::assertSame(3, Verbosity::Debug->value);
    }

    #[Test]
    public function showsNormal(): void
    {
        self::assertFalse(Verbosity::Quiet->showsNormal());
        self::assertTrue(Verbosity::Normal->showsNormal());
        self::assertTrue(Verbosity::Verbose->showsNormal());
        self::assertTrue(Verbosity::Debug->showsNormal());
    }

    #[Test]
    public function showsVerbose(): void
    {
        self::assertFalse(Verbosity::Quiet->showsVerbose());
        self::assertFalse(Verbosity::Normal->showsVerbose());
        self::assertTrue(Verbosity::Verbose->showsVerbose());
        self::assertTrue(Verbosity::Debug->showsVerbose());
    }

    #[Test]
    public function showsDebug(): void
    {
        self::assertFalse(Verbosity::Quiet->showsDebug());
        self::assertFalse(Verbosity::Normal->showsDebug());
        self::assertFalse(Verbosity::Verbose->showsDebug());
        self::assertTrue(Verbosity::Debug->showsDebug());
    }
}
