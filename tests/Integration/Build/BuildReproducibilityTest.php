<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Build;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\ExtensionManifest;
use Pulsar\Extension\Compiler\ExtensionGraphCompiler;
use Pulsar\I18n\Compiler\I18nCatalogCompiler;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function json_encode;
use function mkdir;
use function random_bytes;
use function scandir;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

#[CoversClass(ExtensionGraphCompiler::class)]
#[CoversClass(I18nCatalogCompiler::class)]
final class BuildReproducibilityTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_repro_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function extensionGraphCompilerProducesByteIdenticalOutput(): void
    {
        $pathA = $this->createExtensionWithSource('ext-a');
        $pathB = $this->createExtensionWithSource('ext-b');

        $manifests = [
            ExtensionManifest::fromArray([
                'name' => 'vendor/ext-b',
                'version' => '1.0.0', 'pulsar' => ['min_version' => '1.0.0-rc.11'],
                'extension_class' => 'Vendor\\ExtB\\Extension',
            ], $pathB),
            ExtensionManifest::fromArray([
                'name' => 'vendor/ext-a',
                'version' => '1.0.0', 'pulsar' => ['min_version' => '1.0.0-rc.11'],
                'extension_class' => 'Vendor\\ExtA\\Extension',
            ], $pathA),
        ];

        $compiler = new ExtensionGraphCompiler();

        $result1 = $compiler->compile($manifests);
        $export1 = $compiler->export($result1);

        $result2 = $compiler->compile($manifests);
        $export2 = $compiler->export($result2);

        self::assertSame($export1, $export2, 'Two compilations of identical source must produce byte-identical export');
        self::assertSame($result1->totalHash, $result2->totalHash);
    }

    #[Test]
    public function i18nCatalogCompilerProducesByteIdenticalOutput(): void
    {
        $catalogPath = $this->tempDir . DIRECTORY_SEPARATOR . 'lang';
        mkdir($catalogPath, 0o750, true);

        // Create catalogs
        $this->createJsonCatalog($catalogPath, 'fr', 'messages', [
            'welcome' => 'Bienvenue',
            'goodbye' => 'Au revoir',
        ]);
        $this->createJsonCatalog($catalogPath, 'en', 'messages', [
            'welcome' => 'Welcome',
            'goodbye' => 'Goodbye',
        ]);

        $compiler = new I18nCatalogCompiler();

        $result1 = $compiler->compile($catalogPath);
        $export1 = $compiler->export($result1);

        $result2 = $compiler->compile($catalogPath);
        $export2 = $compiler->export($result2);

        self::assertSame($export1, $export2, 'Two compilations of identical catalogs must produce byte-identical export');
        self::assertSame($result1->totalHash, $result2->totalHash);
    }

    #[Test]
    public function extensionGraphExportUsesForwardSlashPaths(): void
    {
        $path = $this->createExtensionWithSource('ext-a');

        $manifests = [
            ExtensionManifest::fromArray([
                'name' => 'vendor/ext-a',
                'version' => '1.0.0', 'pulsar' => ['min_version' => '1.0.0-rc.11'],
                'extension_class' => 'Vendor\\ExtA\\Extension',
            ], $path),
        ];

        $compiler = new ExtensionGraphCompiler();
        $result = $compiler->compile($manifests);
        $exported = $compiler->export($result);

        // The exported PHP source should not contain backslash file paths
        // (namespace backslashes are expected, but directory separators should be forward slashes)
        self::assertStringContainsString('<?php', $exported);
        self::assertStringContainsString('declare(strict_types=1)', $exported);
    }

    #[Test]
    public function i18nCatalogIndexUsesForwardSlashFileHashKeys(): void
    {
        $catalogPath = $this->tempDir . DIRECTORY_SEPARATOR . 'lang';
        mkdir($catalogPath, 0o750, true);

        $this->createJsonCatalog($catalogPath, 'en', 'messages', ['hello' => 'Hello']);

        $compiler = new I18nCatalogCompiler();
        $index = $compiler->compile($catalogPath);

        foreach (array_keys($index->fileHashes) as $hashKey) {
            self::assertStringNotContainsString('\\', $hashKey, 'File hash keys must use forward slashes: ' . $hashKey);
        }
    }

    #[Test]
    public function extensionCompilationWithDependenciesIsReproducible(): void
    {
        $pathCore = $this->createExtensionWithSource('core');
        $pathAuth = $this->createExtensionWithSource('auth');
        $pathAdmin = $this->createExtensionWithSource('admin');

        $manifests = [
            ExtensionManifest::fromArray([
                'name' => 'vendor/admin',
                'version' => '1.0.0', 'pulsar' => ['min_version' => '1.0.0-rc.11'],
                'extension_class' => 'Vendor\\Admin\\Extension',
                'requires' => ['vendor/auth' => '>=1.0.0'],
            ], $pathAdmin),
            ExtensionManifest::fromArray([
                'name' => 'vendor/core',
                'version' => '1.0.0', 'pulsar' => ['min_version' => '1.0.0-rc.11'],
                'extension_class' => 'Vendor\\Core\\Extension',
            ], $pathCore),
            ExtensionManifest::fromArray([
                'name' => 'vendor/auth',
                'version' => '1.0.0', 'pulsar' => ['min_version' => '1.0.0-rc.11'],
                'extension_class' => 'Vendor\\Auth\\Extension',
                'requires' => ['vendor/core' => '>=1.0.0'],
            ], $pathAuth),
        ];

        $compiler = new ExtensionGraphCompiler();

        $result1 = $compiler->compile($manifests);
        $result2 = $compiler->compile($manifests);

        self::assertSame($result1->toArray(), $result2->toArray());

        // Verify dependency order
        $names = array_map(
            static fn($entry) => $entry->name,
            $result1->extensions,
        );

        $coreIdx = array_search('vendor/core', $names, true);
        $authIdx = array_search('vendor/auth', $names, true);
        $adminIdx = array_search('vendor/admin', $names, true);

        self::assertLessThan($authIdx, $coreIdx);
        self::assertLessThan($adminIdx, $authIdx);
    }

    private function createExtensionWithSource(string $name): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $name;
        $srcDir = $path . DIRECTORY_SEPARATOR . 'src';
        $configDir = $path . DIRECTORY_SEPARATOR . 'config';

        mkdir($srcDir, 0o750, true);
        mkdir($configDir, 0o750, true);

        file_put_contents(
            $srcDir . DIRECTORY_SEPARATOR . 'Extension.php',
            '<?php namespace Vendor\\' . ucfirst($name) . '; class Extension {}',
        );

        file_put_contents(
            $configDir . DIRECTORY_SEPARATOR . 'config.php',
            "<?php return ['enabled' => true];\n",
        );

        return $path;
    }

    /**
     * @param array<string, string> $translations
     */
    private function createJsonCatalog(string $catalogPath, string $locale, string $domain, array $translations): void
    {
        $localeDir = $catalogPath . DIRECTORY_SEPARATOR . $locale;

        if (!is_dir($localeDir)) {
            mkdir($localeDir, 0o750, true);
        }

        file_put_contents(
            $localeDir . DIRECTORY_SEPARATOR . $domain . '.json',
            json_encode($translations, JSON_THROW_ON_ERROR),
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
