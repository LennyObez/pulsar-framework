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
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;

use const DIRECTORY_SEPARATOR;

#[CoversClass(ManifestLoader::class)]
final class ManifestLoaderEdgeTest extends TestCase
{
    private string $tempDir;

    /** @var list<string> Files created during the test, cleaned up in tearDown */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'manifest_loader_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tempDir);
    }

    #[Test]
    public function loadThrowsWhenFileNotFound(): void
    {
        $loader = new ManifestLoader();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        (void) $loader->load($this->tempDir . '/nonexistent.php');
    }

    #[Test]
    public function loadThrowsWhenFileDoesNotReturnArray(): void
    {
        $path = $this->writeTempFile('bad_manifest.php', "<?php\nreturn 'not-an-array';\n");

        $loader = new ManifestLoader();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not return an array');

        (void) $loader->load($path);
    }

    #[Test]
    public function loadReturnsManifestFromValidFile(): void
    {
        $path = $this->writeTempFile('valid_manifest.php', "<?php\nreturn [\n    'extensions' => [],\n    'configHashes' => [],\n    'codeHashes' => [],\n    'totalHash' => 'abc123',\n];\n");

        $loader = new ManifestLoader();
        $manifest = $loader->load($path);

        self::assertSame('abc123', $manifest->totalHash);
        self::assertSame([], $manifest->extensions);
    }

    #[Test]
    public function existsReturnsTrueForExistingFile(): void
    {
        $path = $this->writeTempFile('exists.php', "<?php\nreturn [];\n");

        $loader = new ManifestLoader();

        self::assertTrue($loader->exists($path));
    }

    #[Test]
    public function existsReturnsFalseForMissingFile(): void
    {
        $loader = new ManifestLoader();

        self::assertFalse($loader->exists($this->tempDir . '/missing.php'));
    }

    #[Test]
    public function loadWithExtensionEntries(): void
    {
        $path = $this->writeTempFile('with_entries.php', <<<'PHP'
            <?php
            return [
                'extensions' => [
                    [
                        'name' => 'vendor/my-ext',
                        'version' => '1.2.3',
                        'extensionClass' => 'Vendor\\MyExt\\Extension',
                        'enabled' => true,
                        'dependencies' => ['vendor/base'],
                        'trustTier' => 'verified',
                    ],
                ],
                'configHashes' => ['vendor/my-ext' => 'config-hash'],
                'codeHashes' => ['vendor/my-ext' => 'code-hash'],
                'totalHash' => 'total-hash',
            ];
            PHP);

        $loader = new ManifestLoader();
        $manifest = $loader->load($path);

        self::assertCount(1, $manifest->extensions);
        self::assertSame('vendor/my-ext', $manifest->extensions[0]->name);
        self::assertSame('1.2.3', $manifest->extensions[0]->version);
        self::assertTrue($manifest->extensions[0]->enabled);
        self::assertSame(['vendor/base'], $manifest->extensions[0]->dependencies);
        self::assertSame('verified', $manifest->extensions[0]->trustTier);
    }

    /**
     * Write a temp file and track it for cleanup.
     */
    private function writeTempFile(string $filename, string $content): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, $content);
        $this->createdFiles[] = $path;

        return $path;
    }
}
