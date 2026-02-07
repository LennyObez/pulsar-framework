<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Build;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Build\ArtifactEntry;

#[CoversClass(ArtifactEntry::class)]
final class ArtifactEntryTest extends TestCase
{
    #[Test]
    public function fromArrayCreatesEntryWithAllFields(): void
    {
        $entry = ArtifactEntry::fromArray([
            'path' => 'build/extensions.manifest.php',
            'hash' => 'abc123def456',
            'size' => 4096,
        ]);

        self::assertSame('build/extensions.manifest.php', $entry->path);
        self::assertSame('abc123def456', $entry->hash);
        self::assertSame(4096, $entry->size);
    }

    #[Test]
    public function fromArrayDefaultsMissingFields(): void
    {
        $entry = ArtifactEntry::fromArray([]);

        self::assertSame('', $entry->path);
        self::assertSame('', $entry->hash);
        self::assertSame(0, $entry->size);
    }

    #[Test]
    public function toArrayExportsAllFields(): void
    {
        $entry = new ArtifactEntry(
            path: 'build/routes.compiled.php',
            hash: 'deadbeef',
            size: 2048,
        );

        self::assertSame([
            'path' => 'build/routes.compiled.php',
            'hash' => 'deadbeef',
            'size' => 2048,
        ], $entry->toArray());
    }

    #[Test]
    public function fromArrayToArrayRoundTrip(): void
    {
        $data = [
            'path' => 'build/container.compiled.php',
            'hash' => 'a1b2c3d4e5f6',
            'size' => 8192,
        ];

        $entry = ArtifactEntry::fromArray($data);

        self::assertSame($data, $entry->toArray());
    }
}
