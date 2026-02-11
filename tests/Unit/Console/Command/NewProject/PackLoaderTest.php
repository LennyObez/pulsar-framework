<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\NewProject;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\NewProject\PackLoader;
use Pulsar\Console\Command\NewProject\PackManifest;
use RuntimeException;

use function dirname;

use const DIRECTORY_SEPARATOR;

#[CoversClass(PackLoader::class)]
final class PackLoaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_pack_loader_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_loads_a_valid_pack_manifest(): void
    {
        $this->createPack('test-pack', [
            'name' => 'test-pack',
            'description' => 'A test pack',
            'version' => '1.0.0',
            'requiredPulsarVersion' => '^1.0',
            'compliancePresets' => ['TEST'],
            'files' => [],
            'postInstallCommands' => [],
        ]);

        $loader = new PackLoader($this->tempDir);
        $manifest = $loader->load('test-pack');

        self::assertInstanceOf(PackManifest::class, $manifest);
        self::assertSame('test-pack', $manifest->name);
        self::assertSame('A test pack', $manifest->description);
    }

    #[Test]
    public function it_throws_for_nonexistent_pack(): void
    {
        $loader = new PackLoader($this->tempDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        (void) $loader->load('nonexistent');
    }

    #[Test]
    public function it_throws_for_missing_manifest_file(): void
    {
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'empty-pack', 0o755, true);

        $loader = new PackLoader($this->tempDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing its pack.json');

        (void) $loader->load('empty-pack');
    }

    #[Test]
    public function it_throws_for_invalid_json(): void
    {
        $packDir = $this->tempDir . DIRECTORY_SEPARATOR . 'bad-json';
        mkdir($packDir, 0o755, true);
        file_put_contents($packDir . DIRECTORY_SEPARATOR . 'pack.json', '{invalid json}');

        $loader = new PackLoader($this->tempDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON');

        (void) $loader->load('bad-json');
    }

    #[Test]
    public function it_throws_for_invalid_manifest_structure(): void
    {
        $this->createPack('invalid-manifest', [
            'name' => 'invalid-manifest',
        ]);

        $loader = new PackLoader($this->tempDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid pack manifest');

        (void) $loader->load('invalid-manifest');
    }

    #[Test]
    public function it_lists_available_packs(): void
    {
        $this->createPack('pack-a', [
            'name' => 'pack-a',
            'description' => 'Pack A',
            'version' => '1.0.0',
            'requiredPulsarVersion' => '^1.0',
        ]);

        $this->createPack('pack-b', [
            'name' => 'pack-b',
            'description' => 'Pack B',
            'version' => '1.0.0',
            'requiredPulsarVersion' => '^1.0',
        ]);

        // Create a directory without pack.json (should be excluded)
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'not-a-pack', 0o755, true);

        $loader = new PackLoader($this->tempDir);
        $available = $loader->available();

        self::assertContains('pack-a', $available);
        self::assertContains('pack-b', $available);
        self::assertNotContains('not-a-pack', $available);
    }

    #[Test]
    public function it_returns_empty_list_when_no_packs_directory(): void
    {
        $loader = new PackLoader($this->tempDir . DIRECTORY_SEPARATOR . 'nonexistent');
        $available = $loader->available();

        self::assertSame([], $available);
    }

    #[Test]
    public function it_returns_pack_directory_path(): void
    {
        $loader = new PackLoader($this->tempDir);
        $path = $loader->packDirectory('banking');

        self::assertSame($this->tempDir . DIRECTORY_SEPARATOR . 'banking', $path);
    }

    #[Test]
    public function it_loads_real_banking_pack(): void
    {
        $realPacksDir = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'packs';

        if (!is_dir($realPacksDir . DIRECTORY_SEPARATOR . 'banking')) {
            self::markTestSkipped('Banking pack not found in resources/packs/');
        }

        $loader = new PackLoader($realPacksDir);
        $manifest = $loader->load('banking');

        self::assertSame('banking', $manifest->name);
        self::assertContains('PCI-DSS', $manifest->compliancePresets);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function createPack(string $name, array $manifest): void
    {
        $packDir = $this->tempDir . DIRECTORY_SEPARATOR . $name;
        mkdir($packDir, 0o755, true);
        file_put_contents(
            $packDir . DIRECTORY_SEPARATOR . 'pack.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
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

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
