<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Dev\HotReload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Dev\HotReload\FileChangeEvent;
use Pulsar\Dev\HotReload\FileChangeType;

#[CoversClass(FileChangeEvent::class)]
final class FileChangeEventTest extends TestCase
{
    #[Test]
    public function constructorAssignsAllProperties(): void
    {
        $event = new FileChangeEvent(
            path: '/app/src/Controller.php',
            type: FileChangeType::Modified,
            detectedAt: 1700000000.123,
        );

        self::assertSame('/app/src/Controller.php', $event->path);
        self::assertSame(FileChangeType::Modified, $event->type);
        self::assertSame(1700000000.123, $event->detectedAt);
    }

    #[Test]
    public function filenameReturnsBasename(): void
    {
        $event = new FileChangeEvent(
            path: '/app/src/deep/nested/Controller.php',
            type: FileChangeType::Created,
            detectedAt: 0.0,
        );

        self::assertSame('Controller.php', $event->filename());
    }

    #[Test]
    public function filenameHandlesRootLevelFile(): void
    {
        $event = new FileChangeEvent(
            path: 'index.php',
            type: FileChangeType::Deleted,
            detectedAt: 0.0,
        );

        self::assertSame('index.php', $event->filename());
    }

    #[Test]
    #[DataProvider('changeTypeProvider')]
    public function toArraySerializesCorrectly(FileChangeType $type, string $expectedValue): void
    {
        $event = new FileChangeEvent(
            path: '/app/file.php',
            type: $type,
            detectedAt: 1700000000.5,
        );

        $array = $event->toArray();

        self::assertSame('/app/file.php', $array['path']);
        self::assertSame($expectedValue, $array['type']);
        self::assertSame(1700000000.5, $array['detected_at']);
    }

    /**
     * @return iterable<string, array{FileChangeType, string}>
     */
    public static function changeTypeProvider(): iterable
    {
        yield 'created' => [FileChangeType::Created, 'created'];
        yield 'modified' => [FileChangeType::Modified, 'modified'];
        yield 'deleted' => [FileChangeType::Deleted, 'deleted'];
    }

    #[Test]
    public function toArrayContainsExactlyThreeKeys(): void
    {
        $event = new FileChangeEvent('/a.php', FileChangeType::Modified, 1.0);
        $array = $event->toArray();

        self::assertCount(3, $array);
        self::assertArrayHasKey('path', $array);
        self::assertArrayHasKey('type', $array);
        self::assertArrayHasKey('detected_at', $array);
    }
}
