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
    public function test_compute_path_root_content_returns_slug_segment(): void
    {
        $computer = new PathComputer();

        $path = $computer->computePath(null, 'getting-started');

        self::assertSame('getting-started', $path);
    }

    #[Test]
    public function test_compute_path_nested_content_returns_parent_path_plus_slug(): void
    {
        $computer = new PathComputer();

        $path = $computer->computePath('docs', 'getting-started');

        self::assertSame('docs/getting-started', $path);
    }

    #[Test]
    public function test_compute_path_deeply_nested(): void
    {
        $computer = new PathComputer();

        $path = $computer->computePath('docs/tutorials', 'intro');

        self::assertSame('docs/tutorials/intro', $path);
    }

    #[Test]
    public function test_compute_path_empty_parent_returns_slug(): void
    {
        $computer = new PathComputer();

        $path = $computer->computePath('', 'about');

        self::assertSame('about', $path);
    }
}
