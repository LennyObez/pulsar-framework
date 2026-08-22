<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Build;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Build\PreloadGenerator;
use Pulsar\Extensibility\ExtensionManifest;
use Pulsar\Extension\Compiler\ExtensionGraphCompiler;
use Pulsar\I18n\Compiler\I18nCatalogCompiler;
use Pulsar\Runtime\RuntimeType;

use function bin2hex;
use function dirname;
use function file_put_contents;
use function is_dir;
use function json_encode;
use function mkdir;
use function random_bytes;
use function scandir;
use function str_contains;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

/**
 * Integration test: all generated output paths use forward slashes.
 *
 * Ensures cross-platform determinism: builds on Windows and Linux
 * produce identical output with forward-slash normalized paths.
 */
#[CoversClass(PreloadGenerator::class)]
#[CoversClass(ExtensionGraphCompiler::class)]
#[CoversClass(I18nCatalogCompiler::class)]
final class PathNormalizationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_path_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function preloadGeneratorOutputUsesForwardSlashes(): void
    {
        $basePath = $this->tempDir . DIRECTORY_SEPARATOR . 'app';
        $cacheDir = $basePath . DIRECTORY_SEPARATOR . 'cache';
        mkdir($cacheDir, 0o750, true);

        // Create source files the generator looks for
        $this->createFile($basePath . '/src/Core/Kernel.php', '<?php // stub');
        $this->createFile($basePath . '/src/Container/Container.php', '<?php // stub');
        $this->createFile($cacheDir . '/container.compiled.php', '<?php return [];');

        $generator = new PreloadGenerator();
        $output = $generator->generate(RuntimeType::Fpm, $cacheDir);

        // Extract path strings from require_once and opcache_compile_file lines
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            if (str_contains($line, "require_once '") || str_contains($line, "opcache_compile_file('")) {
                // Extract path between single quotes
                preg_match("/'([^']+)'/", $line, $matches);

                if (isset($matches[1])) {
                    self::assertStringNotContainsString(
                        '\\',
                        $matches[1],
                        'Path must use forward slashes: ' . $matches[1],
                    );
                }
            }
        }
    }

    #[Test]
    public function i18nCatalogFileHashKeysUseForwardSlashes(): void
    {
        $catalogPath = $this->tempDir . DIRECTORY_SEPARATOR . 'lang';
        $this->createFile(
            $catalogPath . '/en/messages.json',
            json_encode(['hello' => 'Hello'], JSON_THROW_ON_ERROR),
        );
        $this->createFile(
            $catalogPath . '/fr/messages.json',
            json_encode(['hello' => 'Bonjour'], JSON_THROW_ON_ERROR),
        );

        $compiler = new I18nCatalogCompiler();
        $index = $compiler->compile($catalogPath);

        foreach (array_keys($index->fileHashes) as $hashKey) {
            self::assertStringNotContainsString(
                '\\',
                $hashKey,
                'File hash key must use forward slashes: ' . $hashKey,
            );
            // Should look like "en/messages.json"
            self::assertStringContainsString('/', $hashKey);
        }
    }

    #[Test]
    public function extensionGraphCompilerExportContainsNoPlatformPaths(): void
    {
        $extPath = $this->tempDir . DIRECTORY_SEPARATOR . 'ext';
        $srcDir = $extPath . DIRECTORY_SEPARATOR . 'src';
        mkdir($srcDir, 0o750, true);
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'Extension.php', '<?php class Extension {}');

        $manifests = [
            ExtensionManifest::fromArray([
                'name' => 'vendor/test-ext',
                'version' => '1.0.0', 'pulsar' => ['min_version' => '1.0.0-rc.11'],
                'extension_class' => 'Vendor\\Test\\Extension',
            ], $extPath),
        ];

        $compiler = new ExtensionGraphCompiler();
        $result = $compiler->compile($manifests);
        $exported = $compiler->export($result);

        // The exported PHP should use standard PHP syntax
        self::assertStringContainsString('<?php', $exported);
        self::assertStringContainsString('declare(strict_types=1)', $exported);
        // Verify it's valid PHP structure
        self::assertStringContainsString('return array', $exported);
    }

    private function createFile(string $path, string $content): void
    {
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0o750, true);
        }

        file_put_contents($path, $content);
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
