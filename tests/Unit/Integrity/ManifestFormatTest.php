<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use function json_decode;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Integrity\Exception\IntegrityException;
use Pulsar\Integrity\IntegrityManifest;
use Pulsar\Integrity\ManifestEntry;
use Pulsar\Integrity\ManifestFormat;

#[CoversClass(ManifestFormat::class)]
final class ManifestFormatTest extends TestCase
{
    #[Test]
    public function it_serializes_manifest_to_json(): void
    {
        $manifest = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 1,
            entries: [
                new ManifestEntry(path: 'src/Kernel.php', hash: 'abc123def456', size: 1024),
            ],
        );

        $json = ManifestFormat::toJson($manifest);
        $data = json_decode($json, true);
        self::assertIsArray($data);

        self::assertSame(1, $data['version']);
        self::assertSame('sha256', $data['algorithm']);
        self::assertSame(1700000000, $data['generated_at']);
        self::assertSame('1.0.0-rc.2', $data['framework_version']);
        self::assertSame(1, $data['entry_count']);
        self::assertIsArray($data['entries']);
        self::assertCount(1, $data['entries']);
        self::assertIsArray($data['entries'][0]);
        self::assertSame('src/Kernel.php', $data['entries'][0]['path']);
        self::assertSame('abc123def456', $data['entries'][0]['hash']);
        self::assertSame(1024, $data['entries'][0]['size']);
    }

    #[Test]
    public function it_serializes_with_explicit_signature(): void
    {
        $manifest = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 0,
            entries: [],
        );

        $json = ManifestFormat::toJson($manifest, 'explicit_sig_hex');
        $data = json_decode($json, true);
        self::assertIsArray($data);

        self::assertSame('explicit_sig_hex', $data['signature']);
    }

    #[Test]
    public function it_serializes_with_manifest_signature_when_no_explicit_given(): void
    {
        $manifest = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 0,
            entries: [],
            signature: 'manifest_sig',
        );

        $json = ManifestFormat::toJson($manifest);
        $data = json_decode($json, true);
        self::assertIsArray($data);

        self::assertSame('manifest_sig', $data['signature']);
    }

    #[Test]
    public function it_prefers_explicit_signature_over_manifest_signature(): void
    {
        $manifest = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 0,
            entries: [],
            signature: 'manifest_sig',
        );

        $json = ManifestFormat::toJson($manifest, 'explicit_sig');
        $data = json_decode($json, true);
        self::assertIsArray($data);

        self::assertSame('explicit_sig', $data['signature']);
    }

    #[Test]
    public function it_omits_signature_when_null(): void
    {
        $manifest = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 0,
            entries: [],
        );

        $json = ManifestFormat::toJson($manifest);
        $data = json_decode($json, true);
        self::assertIsArray($data);

        self::assertArrayNotHasKey('signature', $data);
    }

    #[Test]
    public function it_deserializes_json_to_manifest(): void
    {
        $json = '{"version":1,"algorithm":"sha256","generated_at":1700000000,"framework_version":"1.0.0-rc.2","entry_count":1,"entries":[{"path":"src/Kernel.php","hash":"abc123","size":1024}]}';

        $manifest = ManifestFormat::fromJson($json);

        self::assertSame(1, $manifest->version);
        self::assertSame('sha256', $manifest->algorithm);
        self::assertSame(1700000000, $manifest->generatedAt);
        self::assertSame('1.0.0-rc.2', $manifest->frameworkVersion);
        self::assertSame(1, $manifest->entryCount);
        self::assertCount(1, $manifest->entries);
        self::assertSame('src/Kernel.php', $manifest->entries[0]->path);
        self::assertSame('abc123', $manifest->entries[0]->hash);
        self::assertSame(1024, $manifest->entries[0]->size);
        self::assertNull($manifest->signature);
    }

    #[Test]
    public function it_deserializes_json_with_signature(): void
    {
        $json = '{"version":1,"algorithm":"sha256","generated_at":1700000000,"framework_version":"1.0.0-rc.2","entry_count":0,"entries":[],"signature":"sig_value"}';

        $manifest = ManifestFormat::fromJson($json);

        self::assertSame('sig_value', $manifest->signature);
    }

    #[Test]
    public function it_round_trips_serialize_and_deserialize(): void
    {
        $original = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 2,
            entries: [
                new ManifestEntry(path: 'config/app.php', hash: 'hash1', size: 256),
                new ManifestEntry(path: 'src/Core.php', hash: 'hash2', size: 512),
            ],
            signature: 'test_sig',
        );

        $json = ManifestFormat::toJson($original);
        $restored = ManifestFormat::fromJson($json);

        self::assertSame($original->version, $restored->version);
        self::assertSame($original->algorithm, $restored->algorithm);
        self::assertSame($original->generatedAt, $restored->generatedAt);
        self::assertSame($original->frameworkVersion, $restored->frameworkVersion);
        self::assertSame($original->entryCount, $restored->entryCount);
        self::assertSame($original->signature, $restored->signature);
        self::assertCount(2, $restored->entries);
        self::assertSame('config/app.php', $restored->entries[0]->path);
        self::assertSame('src/Core.php', $restored->entries[1]->path);
    }

    #[Test]
    public function it_throws_on_invalid_json(): void
    {
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('corrupted');

        ManifestFormat::fromJson('{not valid json');
    }

    #[Test]
    public function it_throws_on_non_object_root(): void
    {
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('expected JSON object at root');

        ManifestFormat::fromJson('"just a string"');
    }

    #[Test]
    public function it_throws_on_missing_version(): void
    {
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('missing or invalid "version" field');

        ManifestFormat::fromJson('{"algorithm":"sha256","generated_at":0,"framework_version":"x","entry_count":0,"entries":[]}');
    }

    #[Test]
    public function it_throws_on_invalid_version_type(): void
    {
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('missing or invalid "version" field');

        ManifestFormat::fromJson('{"version":"not_int","algorithm":"sha256","generated_at":0,"framework_version":"x","entry_count":0,"entries":[]}');
    }

    #[Test]
    public function it_throws_on_missing_algorithm(): void
    {
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('missing or invalid "algorithm" field');

        ManifestFormat::fromJson('{"version":1,"generated_at":0,"framework_version":"x","entry_count":0,"entries":[]}');
    }

    #[Test]
    public function it_throws_on_missing_generated_at(): void
    {
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('missing or invalid "generated_at" field');

        ManifestFormat::fromJson('{"version":1,"algorithm":"sha256","framework_version":"x","entry_count":0,"entries":[]}');
    }

    #[Test]
    public function it_throws_on_missing_framework_version(): void
    {
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('missing or invalid "framework_version" field');

        ManifestFormat::fromJson('{"version":1,"algorithm":"sha256","generated_at":0,"entry_count":0,"entries":[]}');
    }

    #[Test]
    public function it_throws_on_missing_entry_count(): void
    {
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('missing or invalid "entry_count" field');

        ManifestFormat::fromJson('{"version":1,"algorithm":"sha256","generated_at":0,"framework_version":"x","entries":[]}');
    }

    #[Test]
    public function it_throws_on_missing_entries(): void
    {
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('missing or invalid "entries" field');

        ManifestFormat::fromJson('{"version":1,"algorithm":"sha256","generated_at":0,"framework_version":"x","entry_count":0}');
    }

    #[Test]
    public function it_throws_on_non_object_entry(): void
    {
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('entry at index 0 is not an object');

        ManifestFormat::fromJson('{"version":1,"algorithm":"sha256","generated_at":0,"framework_version":"x","entry_count":1,"entries":["bad"]}');
    }

    #[Test]
    public function it_throws_on_entry_missing_path(): void
    {
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('entry at index 0 has missing or invalid "path"');

        ManifestFormat::fromJson('{"version":1,"algorithm":"sha256","generated_at":0,"framework_version":"x","entry_count":1,"entries":[{"hash":"abc","size":10}]}');
    }

    #[Test]
    public function it_throws_on_entry_missing_hash(): void
    {
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('entry at index 0 has missing or invalid "hash"');

        ManifestFormat::fromJson('{"version":1,"algorithm":"sha256","generated_at":0,"framework_version":"x","entry_count":1,"entries":[{"path":"a.php","size":10}]}');
    }

    #[Test]
    public function it_throws_on_entry_missing_size(): void
    {
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('entry at index 0 has missing or invalid "size"');

        ManifestFormat::fromJson('{"version":1,"algorithm":"sha256","generated_at":0,"framework_version":"x","entry_count":1,"entries":[{"path":"a.php","hash":"abc"}]}');
    }

    #[Test]
    public function it_reports_correct_entry_index_on_error(): void
    {
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('entry at index 1 has missing or invalid "hash"');

        $json = '{"version":1,"algorithm":"sha256","generated_at":0,"framework_version":"x","entry_count":2,"entries":[{"path":"a.php","hash":"abc","size":10},{"path":"b.php","size":20}]}';
        ManifestFormat::fromJson($json);
    }

    #[Test]
    public function it_ignores_non_string_signature_on_deserialize(): void
    {
        $json = '{"version":1,"algorithm":"sha256","generated_at":0,"framework_version":"x","entry_count":0,"entries":[],"signature":12345}';

        $manifest = ManifestFormat::fromJson($json);

        self::assertNull($manifest->signature);
    }

    #[Test]
    public function it_produces_pretty_printed_json(): void
    {
        $manifest = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 0,
            entries: [],
        );

        $json = ManifestFormat::toJson($manifest);

        self::assertStringContainsString("\n", $json);
        self::assertStringContainsString('    ', $json);
    }

    #[Test]
    public function it_does_not_escape_slashes_in_paths(): void
    {
        $manifest = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 1,
            entries: [
                new ManifestEntry(path: 'src/Core/Kernel.php', hash: 'abc', size: 100),
            ],
        );

        $json = ManifestFormat::toJson($manifest);

        self::assertStringContainsString('src/Core/Kernel.php', $json);
        self::assertStringNotContainsString('src\\/Core\\/Kernel.php', $json);
    }
}
