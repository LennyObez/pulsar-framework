<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Testing\Factory;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Testing\Factory\Sequence;

use function sprintf;

#[CoversClass(Sequence::class)]
final class SequenceTest extends TestCase
{
    #[Test]
    public function generates_sequential_values(): void
    {
        $sequence = new Sequence(static fn(int $i): string => sprintf('user-%d@test.com', $i));

        self::assertSame('user-0@test.com', $sequence());
        self::assertSame('user-1@test.com', $sequence());
        self::assertSame('user-2@test.com', $sequence());
    }

    #[Test]
    public function cycle_rotates_through_values(): void
    {
        $sequence = Sequence::cycle(['red', 'green', 'blue']);

        self::assertSame('red', $sequence());
        self::assertSame('green', $sequence());
        self::assertSame('blue', $sequence());
        self::assertSame('red', $sequence());
    }

    #[Test]
    public function reset_restarts_counter(): void
    {
        $sequence = new Sequence(static fn(int $i): int => $i);

        $sequence();
        $sequence();

        $sequence->reset();

        self::assertSame(0, $sequence());
    }
}
