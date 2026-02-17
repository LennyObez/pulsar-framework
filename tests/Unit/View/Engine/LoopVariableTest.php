<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Engine\LoopVariable;

#[CoversClass(LoopVariable::class)]
final class LoopVariableTest extends TestCase
{
    #[Test]
    public function firstIterationHasCorrectState(): void
    {
        $loop = new LoopVariable(3);
        $loop->step();

        self::assertSame(0, $loop->index);
        self::assertSame(1, $loop->iteration);
        self::assertSame(2, $loop->remaining);
        self::assertSame(3, $loop->count);
        self::assertTrue($loop->first);
        self::assertFalse($loop->last);
        self::assertTrue($loop->even);
        self::assertFalse($loop->odd);
    }

    #[Test]
    public function middleIterationHasCorrectState(): void
    {
        $loop = new LoopVariable(3);
        $loop->step(); // index 0
        $loop->step(); // index 1

        self::assertSame(1, $loop->index);
        self::assertSame(2, $loop->iteration);
        self::assertSame(1, $loop->remaining);
        self::assertFalse($loop->first);
        self::assertFalse($loop->last);
        self::assertFalse($loop->even);
        self::assertTrue($loop->odd);
    }

    #[Test]
    public function lastIterationHasCorrectState(): void
    {
        $loop = new LoopVariable(3);
        $loop->step(); // index 0
        $loop->step(); // index 1
        $loop->step(); // index 2

        self::assertSame(2, $loop->index);
        self::assertSame(3, $loop->iteration);
        self::assertSame(0, $loop->remaining);
        self::assertFalse($loop->first);
        self::assertTrue($loop->last);
        self::assertTrue($loop->even);
        self::assertFalse($loop->odd);
    }

    #[Test]
    public function singleItemLoop(): void
    {
        $loop = new LoopVariable(1);
        $loop->step();

        self::assertTrue($loop->first);
        self::assertTrue($loop->last);
        self::assertSame(0, $loop->remaining);
        self::assertSame(1, $loop->count);
    }

    #[Test]
    public function depthDefaultsToOne(): void
    {
        $loop = new LoopVariable(5);

        self::assertSame(1, $loop->depth);
        self::assertNull($loop->parent);
    }

    #[Test]
    public function nestedLoopTracksParentAndDepth(): void
    {
        $outer = new LoopVariable(2, 1);
        $inner = new LoopVariable(3, 2, $outer);

        self::assertSame(2, $inner->depth);
        self::assertSame($outer, $inner->parent);
        self::assertSame(1, $inner->parent->depth);
    }

    #[Test]
    public function evenOddAlternatesCorrectly(): void
    {
        $loop = new LoopVariable(4);
        $results = [];

        for ($i = 0; $i < 4; $i++) {
            $loop->step();
            $results[] = ['even' => $loop->even, 'odd' => $loop->odd];
        }

        self::assertTrue($results[0]['even']);   // index 0
        self::assertTrue($results[1]['odd']);     // index 1
        self::assertTrue($results[2]['even']);    // index 2
        self::assertTrue($results[3]['odd']);     // index 3
    }
}
