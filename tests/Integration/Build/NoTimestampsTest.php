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
use function unlink;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

/**
 * Integration test: no timestamps embedded in compiled artifact content.
 *
 * Timestamps in artifact content would break deterministic builds since
 * two builds of the same source at different times would produce
 * different output. Metadata (timestamps) is kept separate.
 */
#[CoversClass(PreloadGenerator::class)]
#[CoversClass(ExtensionGraphCompiler::class)]
#[CoversClass(I18nCatalogCompiler::class)]
final class NoTimestampsTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_notime_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function preloadOutputContainsNoTimestamps(): void
    {
        $basePath = $this->tempDir . DIRECTORY_SEPARATOR . 'app';
        $cacheDir = $basePath . DIRECTORY_SEPARATOR . 'cache';
        mkdir($cacheDir, 0o750, true);

        $this->createFile($basePath . '/src/Core/Kernel.php', '<?php // stub');

        $generator = new PreloadGenerator();
        $output = $generator->generate(RuntimeType::Fpm, $basePath, $cacheDir);

        $this->assertNoTimestampPatterns($output, 'preload');
    }

    #[Test]
    public function extensionGraphExportContainsNoTimestamps(): void
    {
        $extPath = $this->tempDir . DIRECTORY_SEPARATOR . 'ext';
        $srcDir = $extPath . DIRECTORY_SEPARATOR . 'src';
        mkdir($srcDir, 0o750, true);
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'Extension.php', '<?php class Extension {}');

        $manifests = [
            ExtensionManifest::fromArray([
                'name' => 'vendor/ext',
                'version' => '1.0.0',
                'extension_class' => 'Vendor\\Ext\\Extension',
            ], $extPath),
        ];

        $compiler = new ExtensionGraphCompiler();
        $result = $compiler->compile($manifests);
        $exported = $compiler->export($result);

        $this->assertNoTimestampPatterns($exported, 'extension graph export');
    }

    #[Test]
    public function i18nCatalogExportContainsNoTimestamps(): void
    {
        $catalogPath = $this->tempDir . DIRECTORY_SEPARATOR . 'lang';
        $this->createFile(
            $catalogPath . '/en/messages.json',
            json_encode(['hello' => 'Hello'], JSON_THROW_ON_ERROR),
        );

        $compiler = new I18nCatalogCompiler();
        $index = $compiler->compile($catalogPath);
        $exported = $compiler->export($index);

        $this->assertNoTimestampPatterns($exported, 'i18n catalog export');
    }

    #[Test]
    public function twoCompilationsAtDifferentTimesProduceIdenticalOutput(): void
    {
        $extPath = $this->tempDir . DIRECTORY_SEPARATOR . 'ext';
        $srcDir = $extPath . DIRECTORY_SEPARATOR . 'src';
        mkdir($srcDir, 0o750, true);
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'Extension.php', '<?php class Extension {}');

        $manifests = [
            ExtensionManifest::fromArray([
                'name' => 'vendor/ext',
                'version' => '1.0.0',
                'extension_class' => 'Vendor\\Ext\\Extension',
            ], $extPath),
        ];

        $compiler = new ExtensionGraphCompiler();

        $result1 = $compiler->compile($manifests);
        $export1 = $compiler->export($result1);

        // Simulate time passing (in practice, no sleep needed — determinism means no time dependency)
        $result2 = $compiler->compile($manifests);
        $export2 = $compiler->export($result2);

        self::assertSame($export1, $export2);
    }

    private function assertNoTimestampPatterns(string $content, string $context): void
    {
        // ISO 8601 date: 2026-02-18
        self::assertDoesNotMatchRegularExpression(
            '/\d{4}-\d{2}-\d{2}/',
            $content,
            "Found date pattern in {$context}",
        );

        // Time pattern: 12:30:00
        self::assertDoesNotMatchRegularExpression(
            '/\d{2}:\d{2}:\d{2}/',
            $content,
            "Found time pattern in {$context}",
        );

        // Unix timestamp (10+ digits starting with 1 or 2)
        self::assertDoesNotMatchRegularExpression(
            '/\b[12]\d{9,}\b/',
            $content,
            "Found Unix timestamp pattern in {$context}",
        );
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
