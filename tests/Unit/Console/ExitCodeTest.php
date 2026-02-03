<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;

#[CoversClass(ExitCode::class)]
final class ExitCodeTest extends TestCase
{
    #[Test]
    public function values(): void
    {
        self::assertSame(0, ExitCode::Success->value);
        self::assertSame(1, ExitCode::Error->value);
        self::assertSame(2, ExitCode::Invalid->value);
    }

    #[Test]
    public function isSuccess(): void
    {
        self::assertTrue(ExitCode::Success->isSuccess());
        self::assertFalse(ExitCode::Error->isSuccess());
        self::assertFalse(ExitCode::Invalid->isSuccess());
    }

    #[Test]
    public function isError(): void
    {
        self::assertFalse(ExitCode::Success->isError());
        self::assertTrue(ExitCode::Error->isError());
        self::assertTrue(ExitCode::Invalid->isError());
    }
}
