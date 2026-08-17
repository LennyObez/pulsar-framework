<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Worker;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\Worker\WorkerState;

#[CoversNothing]
final class WorkerStateTest extends TestCase
{
    #[Test]
    public function it_has_all_expected_cases(): void
    {
        self::assertSame('booting', WorkerState::Booting->value);
        self::assertSame('ready', WorkerState::Ready->value);
        self::assertSame('handling', WorkerState::Handling->value);
        self::assertSame('draining', WorkerState::Draining->value);
        self::assertSame('recycling', WorkerState::Recycling->value);
        self::assertSame('stopped', WorkerState::Stopped->value);
    }

    #[Test]
    public function it_creates_from_string_value(): void
    {
        self::assertSame(WorkerState::Booting, WorkerState::from('booting'));
        self::assertSame(WorkerState::Ready, WorkerState::from('ready'));
        self::assertSame(WorkerState::Handling, WorkerState::from('handling'));
        self::assertSame(WorkerState::Draining, WorkerState::from('draining'));
        self::assertSame(WorkerState::Recycling, WorkerState::from('recycling'));
        self::assertSame(WorkerState::Stopped, WorkerState::from('stopped'));
    }

    #[Test]
    public function it_has_six_cases(): void
    {
        self::assertCount(6, WorkerState::cases());
    }
}
