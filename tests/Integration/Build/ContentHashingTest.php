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

/**
 * Integration test: content hashing detects changes in config, code, and i18n.
 *
 * Validates that the build pipeline's staleness detection correctly
 * identifies when source inputs have changed.
 */
#[CoversClass(ExtensionGraphCompiler::class)]
#[CoversClass(I18nCatalogCompiler::class)]
final class ContentHashingTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_hash_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function configChangeDetectedInExtensionHash(): void
    {
        $extPath = $this->tempDir . DIRECTORY_SEPARATOR . 'ext';
        $configDir = $extPath . DIRECTORY_SEPARATOR . 'config';
        $srcDir = $extPath . DIRECTORY_SEPARATOR . 'src';
        mkdir($configDir, 0o750, true);
        mkdir($srcDir, 0o750, true);

        // Initial config
        file_put_contents($configDir . DIRECTORY_SEPARATOR . 'settings.php', '<?php return ["debug" => false];');
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
        $configHash1 = $result1->configHashes['vendor/ext'];

        // Modify config
        file_put_contents($configDir . DIRECTORY_SEPARATOR . 'settings.php', '<?php return ["debug" => true, "level" => "verbose"];');

        $result2 = $compiler->compile($manifests);
        $configHash2 = $result2->configHashes['vendor/ext'];

        self::assertNotSame($configHash1, $configHash2, 'Config change must produce a different config hash');
    }

    #[Test]
    public function codeChangeDetectedInExtensionHash(): void
    {
        $extPath = $this->tempDir . DIRECTORY_SEPARATOR . 'ext';
        $srcDir = $extPath . DIRECTORY_SEPARATOR . 'src';
        mkdir($srcDir, 0o750, true);

        // Initial code
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'Extension.php', '<?php class Extension { public function boot(): void {} }');

        $manifests = [
            ExtensionManifest::fromArray([
                'name' => 'vendor/ext',
                'version' => '1.0.0',
                'extension_class' => 'Vendor\\Ext\\Extension',
            ], $extPath),
        ];

        $compiler = new ExtensionGraphCompiler();
        $result1 = $compiler->compile($manifests);
        $codeHash1 = $result1->codeHashes['vendor/ext'];

        // Modify code
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'Extension.php', '<?php class Extension { public function boot(): void { log("booted"); } }');

        $result2 = $compiler->compile($manifests);
        $codeHash2 = $result2->codeHashes['vendor/ext'];

        self::assertNotSame($codeHash1, $codeHash2, 'Code change must produce a different code hash');
    }

    #[Test]
    public function totalHashChangesWhenAnyInputChanges(): void
    {
        $extPath = $this->tempDir . DIRECTORY_SEPARATOR . 'ext';
        $srcDir = $extPath . DIRECTORY_SEPARATOR . 'src';
        $configDir = $extPath . DIRECTORY_SEPARATOR . 'config';
        mkdir($srcDir, 0o750, true);
        mkdir($configDir, 0o750, true);

        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'Extension.php', '<?php class Extension {}');
        file_put_contents($configDir . DIRECTORY_SEPARATOR . 'config.php', '<?php return [];');

        $manifests = [
            ExtensionManifest::fromArray([
                'name' => 'vendor/ext',
                'version' => '1.0.0',
                'extension_class' => 'Vendor\\Ext\\Extension',
            ], $extPath),
        ];

        $compiler = new ExtensionGraphCompiler();
        $result1 = $compiler->compile($manifests);

        // Change code
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'Extension.php', '<?php class Extension { /* modified */ }');

        $result2 = $compiler->compile($manifests);

        self::assertNotSame($result1->totalHash, $result2->totalHash, 'Total hash must change when code changes');
    }

    #[Test]
    public function i18nHashDetectsTranslationChanges(): void
    {
        $catalogPath = $this->tempDir . DIRECTORY_SEPARATOR . 'lang';

        $this->createJsonCatalog($catalogPath, 'en', 'messages', ['hello' => 'Hello']);

        $compiler = new I18nCatalogCompiler();
        $result1 = $compiler->compile($catalogPath);

        // Modify translation
        $this->createJsonCatalog($catalogPath, 'en', 'messages', ['hello' => 'Hello World']);

        $result2 = $compiler->compile($catalogPath);

        self::assertNotSame($result1->totalHash, $result2->totalHash, 'Translation change must produce a different total hash');
    }

    #[Test]
    public function i18nHashDetectsNewLocale(): void
    {
        $catalogPath = $this->tempDir . DIRECTORY_SEPARATOR . 'lang';

        $this->createJsonCatalog($catalogPath, 'en', 'messages', ['hello' => 'Hello']);

        $compiler = new I18nCatalogCompiler();
        $result1 = $compiler->compile($catalogPath);

        // Add a new locale
        $this->createJsonCatalog($catalogPath, 'fr', 'messages', ['hello' => 'Bonjour']);

        $result2 = $compiler->compile($catalogPath);

        self::assertNotSame($result1->totalHash, $result2->totalHash, 'New locale must produce a different total hash');
        self::assertCount(1, $result1->locales);
        self::assertCount(2, $result2->locales);
    }

    #[Test]
    public function i18nHashDetectsNewDomain(): void
    {
        $catalogPath = $this->tempDir . DIRECTORY_SEPARATOR . 'lang';

        $this->createJsonCatalog($catalogPath, 'en', 'messages', ['hello' => 'Hello']);

        $compiler = new I18nCatalogCompiler();
        $result1 = $compiler->compile($catalogPath);

        // Add a new domain
        $this->createJsonCatalog($catalogPath, 'en', 'validation', ['required' => 'Required']);

        $result2 = $compiler->compile($catalogPath);

        self::assertNotSame($result1->totalHash, $result2->totalHash, 'New domain must produce a different total hash');
    }

    #[Test]
    public function unchangedInputProducesIdenticalHash(): void
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
        $result2 = $compiler->compile($manifests);

        self::assertSame($result1->totalHash, $result2->totalHash);
        self::assertSame($result1->configHashes, $result2->configHashes);
        self::assertSame($result1->codeHashes, $result2->codeHashes);
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
