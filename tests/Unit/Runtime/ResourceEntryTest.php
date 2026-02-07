<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\ResourceEntry;
use ReflectionClass;

#[CoversClass(ResourceEntry::class)]
final class ResourceEntryTest extends TestCase
{
    #[Test]
    public function it_constructs_with_all_properties(): void
    {
        $entry = new ResourceEntry(
            id: 'conn-1',
            type: 'database',
            description: 'MySQL connection',
            trackedAt: 1234567890.123,
        );

        self::assertSame('conn-1', $entry->id);
        self::assertSame('database', $entry->type);
        self::assertSame('MySQL connection', $entry->description);
        self::assertSame(1234567890.123, $entry->trackedAt);
    }

    #[Test]
    public function it_is_readonly(): void
    {
        $entry = new ResourceEntry(
            id: 'res-1',
            type: 'stream',
            description: 'File handle',
            trackedAt: 1000.0,
        );

        $reflection = new ReflectionClass($entry);
        self::assertTrue($reflection->isReadOnly());
    }
}
