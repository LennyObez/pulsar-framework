<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Integrity\ManifestEntry;
use ReflectionClass;

#[CoversClass(ManifestEntry::class)]
final class ManifestEntryTest extends TestCase
{
    #[Test]
    public function constructorSetsPath(): void
    {
        $entry = new ManifestEntry(path: 'src/Kernel.php', hash: 'abc123', size: 1024);

        self::assertSame('src/Kernel.php', $entry->path);
    }

    #[Test]
    public function constructorSetsHash(): void
    {
        $entry = new ManifestEntry(path: 'src/Kernel.php', hash: 'sha256hex', size: 512);

        self::assertSame('sha256hex', $entry->hash);
    }

    #[Test]
    public function constructorSetsSize(): void
    {
        $entry = new ManifestEntry(path: 'file.php', hash: 'h', size: 999);

        self::assertSame(999, $entry->size);
    }

    #[Test]
    public function isReadonly(): void
    {
        $ref = new ReflectionClass(ManifestEntry::class);

        self::assertTrue($ref->isReadOnly());
    }

    #[Test]
    public function isFinal(): void
    {
        $ref = new ReflectionClass(ManifestEntry::class);

        self::assertTrue($ref->isFinal());
    }

    #[Test]
    public function zeroSizeIsAllowed(): void
    {
        $entry = new ManifestEntry(path: 'empty.php', hash: 'e3b0c44', size: 0);

        self::assertSame(0, $entry->size);
    }

    #[Test]
    public function pathCanContainNestedDirectories(): void
    {
        $entry = new ManifestEntry(path: 'src/Security/Audit/AuditEntry.php', hash: 'abc', size: 100);

        self::assertSame('src/Security/Audit/AuditEntry.php', $entry->path);
    }
}
