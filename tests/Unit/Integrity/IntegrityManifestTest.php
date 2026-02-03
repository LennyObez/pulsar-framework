<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Integrity\IntegrityManifest;
use Pulsar\Integrity\ManifestEntry;
use ReflectionClass;

#[CoversClass(IntegrityManifest::class)]
final class IntegrityManifestTest extends TestCase
{
    #[Test]
    public function it_constructs_with_all_required_fields(): void
    {
        $entries = [
            new ManifestEntry(path: 'src/Kernel.php', hash: 'abc123', size: 1024),
            new ManifestEntry(path: 'config/app.php', hash: 'def456', size: 512),
        ];

        $manifest = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 2,
            entries: $entries,
        );

        self::assertSame(1, $manifest->version);
        self::assertSame('sha256', $manifest->algorithm);
        self::assertSame(1700000000, $manifest->generatedAt);
        self::assertSame('1.0.0-rc.2', $manifest->frameworkVersion);
        self::assertSame(2, $manifest->entryCount);
        self::assertCount(2, $manifest->entries);
        self::assertNull($manifest->signature);
    }

    #[Test]
    public function it_accepts_optional_signature(): void
    {
        $manifest = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 0,
            entries: [],
            signature: 'sig_hex_value',
        );

        self::assertSame('sig_hex_value', $manifest->signature);
    }

    #[Test]
    public function it_defaults_signature_to_null(): void
    {
        $manifest = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 0,
            entries: [],
        );

        self::assertNull($manifest->signature);
    }

    #[Test]
    public function it_is_readonly(): void
    {
        $manifest = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 1,
            entries: [new ManifestEntry(path: 'a.php', hash: 'aaa', size: 10)],
        );

        $reflection = new ReflectionClass($manifest);
        self::assertTrue($reflection->isReadOnly());
    }

    #[Test]
    public function it_preserves_entry_order(): void
    {
        $entries = [
            new ManifestEntry(path: 'z.php', hash: 'zzz', size: 100),
            new ManifestEntry(path: 'a.php', hash: 'aaa', size: 50),
            new ManifestEntry(path: 'm.php', hash: 'mmm', size: 75),
        ];

        $manifest = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 3,
            entries: $entries,
        );

        self::assertSame('z.php', $manifest->entries[0]->path);
        self::assertSame('a.php', $manifest->entries[1]->path);
        self::assertSame('m.php', $manifest->entries[2]->path);
    }

    #[Test]
    public function it_allows_empty_entries(): void
    {
        $manifest = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 0,
            entries: [],
        );

        self::assertSame(0, $manifest->entryCount);
        self::assertSame([], $manifest->entries);
    }
}
