<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Extension\Cms\Content\PathComputer;
use Pulsar\Extension\Cms\Exception\CmsException;

#[CoversClass(PathComputer::class)]
final class PathComputerDbTest extends TestCase
{
    private PathComputer $computer;

    protected function setUp(): void
    {
        $this->computer = new PathComputer();
    }

    // ── detectCycle ─────────────────────────────────────────────────

    #[Test]
    public function detectCycleReturnsTrueWhenContentIdEqualsParentId(): void
    {
        $db = $this->createStub(ConnectionInterface::class);

        self::assertTrue($this->computer->detectCycle('c1', 'c1', $db));
    }

    #[Test]
    public function detectCycleReturnsTrueWhenAncestorChainContainsContentId(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(Result::fromArrays([['id' => 'c1']]));

        self::assertTrue($this->computer->detectCycle('c1', 'c2', $db));
    }

    #[Test]
    public function detectCycleReturnsFalseWhenNoAncestorMatch(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(Result::fromArrays([]));

        self::assertFalse($this->computer->detectCycle('c1', 'c2', $db));
    }

    // ── enforceMaxDepth ─────────────────────────────────────────────

    #[Test]
    public function enforceMaxDepthReturnsFalseWhenNoParentFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(Result::fromArrays([]));

        self::assertFalse($this->computer->enforceMaxDepth('c1', 5, $db));
    }

    #[Test]
    public function enforceMaxDepthReturnsFalseWhenDepthWithinLimit(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(Result::fromArrays([['max_depth' => 3]]));

        // depth 3 + 1 child = 4, not exceeding maxDepth 5
        self::assertFalse($this->computer->enforceMaxDepth('c1', 5, $db));
    }

    #[Test]
    public function enforceMaxDepthReturnsTrueWhenDepthExceedsLimit(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(Result::fromArrays([['max_depth' => 5]]));

        // depth 5 + 1 child = 6, exceeding maxDepth 5
        self::assertTrue($this->computer->enforceMaxDepth('c1', 5, $db));
    }

    #[Test]
    public function enforceMaxDepthReturnsFalseWhenMaxDepthIsNull(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(Result::fromArrays([['max_depth' => null]]));

        self::assertFalse($this->computer->enforceMaxDepth('c1', 5, $db));
    }

    // ── validateParentAssignment ─────────────────────────────────────

    #[Test]
    public function validateParentAssignmentThrowsOnCycle(): void
    {
        $db = $this->createStub(ConnectionInterface::class);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('Circular parent reference');

        $this->computer->validateParentAssignment('c1', 'c1', 10, $db);
    }

    #[Test]
    public function validateParentAssignmentThrowsOnMaxDepthExceeded(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        // First query (detectCycle) returns empty (no cycle)
        // Second query (enforceMaxDepth) returns depth exceeding limit
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([]),
            Result::fromArrays([['max_depth' => 10]]),
        );

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('Maximum hierarchy depth');

        $this->computer->validateParentAssignment('c1', 'c2', 10, $db);
    }

    #[Test]
    public function validateParentAssignmentPassesWhenValid(): void
    {
        $this->expectNotToPerformAssertions();

        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([]),
            Result::fromArrays([['max_depth' => 2]]),
        );

        // Should not throw — depth 2 + 1 = 3, not exceeding maxDepth 10
        $this->computer->validateParentAssignment('c1', 'c2', 10, $db);
    }

    // ── recomputeDescendantPaths ─────────────────────────────────────

    #[Test]
    public function recomputeDescendantPathsReturnsEmptyWhenNoParentTranslation(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(Result::fromArrays([]));

        $changes = $this->computer->recomputeDescendantPaths('c1', 'en', $db);

        self::assertSame([], $changes);
    }

    #[Test]
    public function recomputeDescendantPathsReturnsEmptyWhenNoDescendants(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['path' => 'docs']]),
            Result::fromArrays([]),
        );

        $changes = $this->computer->recomputeDescendantPaths('c1', 'en', $db);

        self::assertSame([], $changes);
    }

    #[Test]
    public function recomputeDescendantPathsComputesNewPathsAndUpdatesDb(): void
    {
        $executedSqls = [];
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            // Parent's new path
            Result::fromArrays([['path' => 'new-docs']]),
            // Descendants
            Result::fromArrays([
                [
                    'id' => 'child-1',
                    'parent_id' => 'c1',
                    'depth' => 1,
                    'slug_segment' => 'getting-started',
                    'old_path' => 'old-docs/getting-started',
                ],
            ]),
        );
        $db->method('execute')->willReturn(1);

        $changes = $this->computer->recomputeDescendantPaths('c1', 'en', $db);

        self::assertCount(1, $changes);
        self::assertSame('child-1', $changes[0]['contentId']);
        self::assertSame('en', $changes[0]['locale']);
        self::assertSame('old-docs/getting-started', $changes[0]['oldPath']);
        self::assertSame('new-docs/getting-started', $changes[0]['newPath']);
    }
}
