<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Event\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Event\Attribute\StormOverride;

#[CoversClass(StormOverride::class)]
final class StormOverrideTest extends TestCase
{
    #[Test]
    public function normalValueIsPreserved(): void
    {
        $attr = new StormOverride(64);

        self::assertSame(64, $attr->maxDepth);
    }

    #[Test]
    public function clampedToMinimumOne(): void
    {
        $attr = new StormOverride(0);

        self::assertSame(1, $attr->maxDepth);
    }

    #[Test]
    public function negativeClampedToOne(): void
    {
        $attr = new StormOverride(-10);

        self::assertSame(1, $attr->maxDepth);
    }

    #[Test]
    public function clampedToMaximum256(): void
    {
        $attr = new StormOverride(999);

        self::assertSame(256, $attr->maxDepth);
    }

    #[Test]
    public function boundaryValueOne(): void
    {
        $attr = new StormOverride(1);

        self::assertSame(1, $attr->maxDepth);
    }

    #[Test]
    public function boundaryValue256(): void
    {
        $attr = new StormOverride(256);

        self::assertSame(256, $attr->maxDepth);
    }
}
