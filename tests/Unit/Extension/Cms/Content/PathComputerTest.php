<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\PathComputer;

#[CoversClass(PathComputer::class)]
final class PathComputerTest extends TestCase
{
    // ── computePath (pure logic, no DB) ──────────────────────────────

    #[Test]
    public function computePathRootContentReturnsSlugSegment(): void
    {
        $computer = new PathComputer();

        $path = $computer->computePath(null, 'getting-started');

        self::assertSame('getting-started', $path);
    }

    #[Test]
    public function computePathNestedContentReturnsParentPathPlusSlug(): void
    {
        $computer = new PathComputer();

        $path = $computer->computePath('docs', 'getting-started');

        self::assertSame('docs/getting-started', $path);
    }

    #[Test]
    public function computePathDeeplyNested(): void
    {
        $computer = new PathComputer();

        $path = $computer->computePath('docs/tutorials', 'intro');

        self::assertSame('docs/tutorials/intro', $path);
    }

    #[Test]
    public function computePathEmptyParentReturnsSlug(): void
    {
        $computer = new PathComputer();

        $path = $computer->computePath('', 'about');

        self::assertSame('about', $path);
    }
}
