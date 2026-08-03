<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Compiler\ManifestLoader;
use RuntimeException;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function scandir;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(ManifestLoader::class)]
final class ManifestLoaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_loader_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function loadReturnsCompiledManifestFromValidFile(): void
    {
        $filePath = $this->tempDir . DIRECTORY_SEPARATOR . 'extensions.manifest.php';
        file_put_contents($filePath, <<<'PHP'
            <?php
            return [
                'extensions' => [
                    [
                        'name' => 'vendor/test',
                        'version' => '1.0.0',
                        'extensionClass' => 'Vendor\\Test\\Extension',
                        'enabled' => true,
                        'dependencies' => [],
                        'trustTier' => 'community',
                    ],
                ],
                'configHashes' => ['vendor/test' => 'ch_abc'],
                'codeHashes' => ['vendor/test' => 'cdh_abc'],
                'totalHash' => 'total_abc',
            ];
            PHP);

        $loader = new ManifestLoader();
        $manifest = $loader->load($filePath);

        self::assertCount(1, $manifest->extensions);
        self::assertSame('vendor/test', $manifest->extensions[0]->name);
        self::assertSame('total_abc', $manifest->totalHash);
    }

    #[Test]
    public function loadThrowsOnMissingFile(): void
    {
        $loader = new ManifestLoader();
        $missingPath = $this->tempDir . DIRECTORY_SEPARATOR . 'nonexistent.php';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('not found');

        (void) $loader->load($missingPath);
    }

    #[Test]
    public function existsReturnsTrueForExistingFile(): void
    {
        $filePath = $this->tempDir . DIRECTORY_SEPARATOR . 'existing.php';
        file_put_contents($filePath, '<?php return [];');

        $loader = new ManifestLoader();

        self::assertTrue($loader->exists($filePath));
    }

    #[Test]
    public function existsReturnsFalseForMissingFile(): void
    {
        $loader = new ManifestLoader();

        self::assertFalse($loader->exists($this->tempDir . DIRECTORY_SEPARATOR . 'nonexistent.php'));
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
