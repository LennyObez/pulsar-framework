<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\FlowStep;

#[CoversClass(FlowStep::class)]
final class FlowStepTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $step = new FlowStep(
            source: '/home',
            target: '/about',
            visitors: 150,
            depth: 1,
        );

        self::assertSame('/home', $step->source);
        self::assertSame('/about', $step->target);
        self::assertSame(150, $step->visitors);
        self::assertSame(1, $step->depth);
    }

    #[Test]
    public function depthDefaultsToZero(): void
    {
        $step = new FlowStep(
            source: '/',
            target: '/blog',
            visitors: 42,
        );

        self::assertSame(0, $step->depth);
    }

    #[Test]
    public function allowsZeroVisitors(): void
    {
        $step = new FlowStep(
            source: '/checkout',
            target: '/thank-you',
            visitors: 0,
            depth: 3,
        );

        self::assertSame(0, $step->visitors);
    }
}
