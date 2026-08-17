<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\RuntimeStatus;

#[CoversNothing]
final class RuntimeStatusTest extends TestCase
{
    #[Test]
    public function it_has_all_expected_cases(): void
    {
        self::assertSame('stopped', RuntimeStatus::Stopped->value);
        self::assertSame('starting', RuntimeStatus::Starting->value);
        self::assertSame('running', RuntimeStatus::Running->value);
        self::assertSame('draining', RuntimeStatus::Draining->value);
        self::assertSame('stopping', RuntimeStatus::Stopping->value);
    }

    #[Test]
    public function it_creates_from_string_value(): void
    {
        self::assertSame(RuntimeStatus::Running, RuntimeStatus::from('running'));
        self::assertSame(RuntimeStatus::Stopped, RuntimeStatus::from('stopped'));
    }

    #[Test]
    public function it_has_five_cases(): void
    {
        self::assertCount(5, RuntimeStatus::cases());
    }
}
