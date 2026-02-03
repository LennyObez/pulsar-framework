<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use function bin2hex;

use const DIRECTORY_SEPARATOR;

use function dirname;
use function file_put_contents;
use function hash_file;
use function is_dir;
use function is_link;
use function mkdir;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Integrity\IntegrityManifest;
use Pulsar\Integrity\ManifestBuilder;
use Pulsar\Integrity\ManifestEntry;

use function random_bytes;
use function rmdir;
use function scandir;
use function strlen;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(ManifestBuilder::class)]
final class ManifestBuilderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_integrity_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_builds_manifest_from_include_patterns(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');
        $this->createFile('src/Router.php', '<?php class Router {}');

        $builder = new ManifestBuilder($this->tempDir);
        $manifest = $builder->build(['src/**/*.php'], []);

        self::assertInstanceOf(IntegrityManifest::class, $manifest);
        self::assertSame(2, $manifest->entryCount);
        self::assertCount(2, $manifest->entries);
    }

    #[Test]
    public function it_computes_sha256_hashes(): void
    {
        $content = '<?php echo "hello";';
        $this->createFile('src/Hello.php', $content);

        $builder = new ManifestBuilder($this->tempDir);
        $manifest = $builder->build(['src/**/*.php'], []);

        self::assertCount(1, $manifest->entries);

        $expectedHash = hash_file('sha256', $this->tempDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Hello.php');
        self::assertSame($expectedHash, $manifest->entries[0]->hash);
    }

    #[Test]
    public function it_records_file_sizes(): void
    {
        $content = '<?php class Sized {}';
        $this->createFile('src/Sized.php', $content);

        $builder = new ManifestBuilder($this->tempDir);
        $manifest = $builder->build(['src/**/*.php'], []);

        self::assertCount(1, $manifest->entries);
        self::assertSame(strlen($content), $manifest->entries[0]->size);
    }

    #[Test]
    public function it_sorts_entries_by_path(): void
    {
        $this->createFile('src/Z.php', '<?php class Z {}');
        $this->createFile('src/A.php', '<?php class A {}');
        $this->createFile('src/M.php', '<?php class M {}');

        $builder = new ManifestBuilder($this->tempDir);
        $manifest = $builder->build(['src/**/*.php'], []);

        self::assertSame(3, $manifest->entryCount);
        self::assertSame('src/A.php', $manifest->entries[0]->path);
        self::assertSame('src/M.php', $manifest->entries[1]->path);
        self::assertSame('src/Z.php', $manifest->entries[2]->path);
    }

    #[Test]
    public function it_uses_forward_slashes_in_paths(): void
    {
        $this->createFile('src/Core/Kernel.php', '<?php class Kernel {}');

        $builder = new ManifestBuilder($this->tempDir);
        $manifest = $builder->build(['src/**/*.php'], []);

        self::assertCount(1, $manifest->entries);
        self::assertSame('src/Core/Kernel.php', $manifest->entries[0]->path);
        self::assertStringNotContainsString('\\', $manifest->entries[0]->path);
    }

    #[Test]
    public function it_excludes_files_matching_exclude_patterns(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');
        $this->createFile('vendor/autoload.php', '<?php // autoload');

        $builder = new ManifestBuilder($this->tempDir);
        $manifest = $builder->build(['src/**/*.php', 'vendor/**/*.php'], ['vendor/**']);

        self::assertSame(1, $manifest->entryCount);
        self::assertSame('src/Kernel.php', $manifest->entries[0]->path);
    }

    #[Test]
    public function it_handles_multiple_include_patterns(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');
        $this->createFile('config/app.php', '<?php return [];');

        $builder = new ManifestBuilder($this->tempDir);
        $manifest = $builder->build(['src/**/*.php', 'config/**/*.php'], []);

        self::assertSame(2, $manifest->entryCount);

        $paths = array_map(static fn(ManifestEntry $e): string => $e->path, $manifest->entries);
        self::assertContains('src/Kernel.php', $paths);
        self::assertContains('config/app.php', $paths);
    }

    #[Test]
    public function it_handles_multiple_exclude_patterns(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');
        $this->createFile('vendor/autoload.php', '<?php // autoload');
        $this->createFile('var/cache/compiled.php', '<?php // compiled');

        $builder = new ManifestBuilder($this->tempDir);
        $manifest = $builder->build(
            ['src/**/*.php', 'vendor/**/*.php', 'var/**/*.php'],
            ['vendor/**', 'var/**'],
        );

        self::assertSame(1, $manifest->entryCount);
        self::assertSame('src/Kernel.php', $manifest->entries[0]->path);
    }

    #[Test]
    public function it_returns_empty_manifest_when_no_files_match(): void
    {
        $builder = new ManifestBuilder($this->tempDir);
        $manifest = $builder->build(['nonexistent/**/*.php'], []);

        self::assertSame(0, $manifest->entryCount);
        self::assertSame([], $manifest->entries);
    }

    #[Test]
    public function it_skips_nonexistent_include_directories(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');

        $builder = new ManifestBuilder($this->tempDir);
        $manifest = $builder->build(['src/**/*.php', 'missing/**/*.php'], []);

        self::assertSame(1, $manifest->entryCount);
    }

    #[Test]
    public function it_discovers_files_in_nested_directories(): void
    {
        $this->createFile('src/Core/Kernel.php', '<?php class Kernel {}');
        $this->createFile('src/Core/Http/Request.php', '<?php class Request {}');
        $this->createFile('src/Routing/Router.php', '<?php class Router {}');

        $builder = new ManifestBuilder($this->tempDir);
        $manifest = $builder->build(['src/**/*.php'], []);

        self::assertSame(3, $manifest->entryCount);

        $paths = array_map(static fn(ManifestEntry $e): string => $e->path, $manifest->entries);
        self::assertContains('src/Core/Kernel.php', $paths);
        self::assertContains('src/Core/Http/Request.php', $paths);
        self::assertContains('src/Routing/Router.php', $paths);
    }

    #[Test]
    public function it_sets_manifest_metadata(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');

        $before = time();
        $builder = new ManifestBuilder($this->tempDir);
        $manifest = $builder->build(['src/**/*.php'], []);
        $after = time();

        self::assertSame(1, $manifest->version);
        self::assertSame('sha256', $manifest->algorithm);
        self::assertGreaterThanOrEqual($before, $manifest->generatedAt);
        self::assertLessThanOrEqual($after, $manifest->generatedAt);
        self::assertNotEmpty($manifest->frameworkVersion);
        self::assertNull($manifest->signature);
    }

    #[Test]
    public function it_does_not_deduplicate_across_overlapping_patterns(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');

        $builder = new ManifestBuilder($this->tempDir);
        // Both patterns match the same file — but it should only appear once
        $manifest = $builder->build(['src/**/*.php', 'src/*.php'], []);

        // The builder uses a map to deduplicate so the file appears once
        self::assertSame(1, $manifest->entryCount);
    }

    #[Test]
    public function it_only_matches_files_not_directories(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'EmptyDir', 0o750, true);

        $builder = new ManifestBuilder($this->tempDir);
        $manifest = $builder->build(['src/**/*.php'], []);

        self::assertSame(1, $manifest->entryCount);
    }

    #[Test]
    public function it_produces_deterministic_results_for_same_files(): void
    {
        $this->createFile('src/A.php', '<?php class A {}');
        $this->createFile('src/B.php', '<?php class B {}');

        $builder = new ManifestBuilder($this->tempDir);
        $manifest1 = $builder->build(['src/**/*.php'], []);
        $manifest2 = $builder->build(['src/**/*.php'], []);

        self::assertSame($manifest1->entryCount, $manifest2->entryCount);

        for ($i = 0; $i < $manifest1->entryCount; $i++) {
            self::assertSame($manifest1->entries[$i]->path, $manifest2->entries[$i]->path);
            self::assertSame($manifest1->entries[$i]->hash, $manifest2->entries[$i]->hash);
            self::assertSame($manifest1->entries[$i]->size, $manifest2->entries[$i]->size);
        }
    }

    private function createFile(string $relativePath, string $content): void
    {
        $absolutePath = $this->tempDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $dir = dirname($absolutePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0o750, true);
        }

        file_put_contents($absolutePath, $content);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
