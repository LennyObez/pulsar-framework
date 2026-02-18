<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Compiler\CompiledCatalogIndex;
use Pulsar\I18n\Compiler\I18nCatalogCompiler;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function json_encode;
use function mkdir;
use function random_bytes;
use function scandir;
use function strlen;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

#[CoversClass(I18nCatalogCompiler::class)]
#[CoversClass(CompiledCatalogIndex::class)]
final class I18nCatalogCompilerTest extends TestCase
{
    private string $catalogPath;

    protected function setUp(): void
    {
        $this->catalogPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_i18n_test_' . bin2hex(random_bytes(8));
        mkdir($this->catalogPath, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->catalogPath);
    }

    #[Test]
    public function compilationWithJsonCatalogs(): void
    {
        $this->createJsonCatalog('en', 'messages', [
            'greeting' => 'Hello',
            'farewell' => 'Goodbye',
        ]);

        $compiler = new I18nCatalogCompiler();
        $index = $compiler->compile($this->catalogPath);

        self::assertSame(['en'], $index->locales);
        self::assertArrayHasKey('en', $index->index);
        self::assertArrayHasKey('messages', $index->index['en']);
        self::assertContains('greeting', $index->index['en']['messages']);
        self::assertContains('farewell', $index->index['en']['messages']);
    }

    #[Test]
    public function compilationWithPhpCatalogs(): void
    {
        $this->createPhpCatalog('fr', 'validation', [
            'required' => 'Ce champ est requis',
            'email' => 'Adresse email invalide',
        ]);

        $compiler = new I18nCatalogCompiler();
        $index = $compiler->compile($this->catalogPath);

        self::assertSame(['fr'], $index->locales);
        self::assertArrayHasKey('fr', $index->index);
        self::assertArrayHasKey('validation', $index->index['fr']);
        self::assertContains('required', $index->index['fr']['validation']);
        self::assertContains('email', $index->index['fr']['validation']);
    }

    #[Test]
    public function deterministicOutputSortedLocalesDomainKeys(): void
    {
        // Create catalogs in non-alphabetical order
        $this->createJsonCatalog('fr', 'validation', ['z_field' => 'z', 'a_field' => 'a']);
        $this->createJsonCatalog('en', 'messages', ['goodbye' => 'bye', 'hello' => 'hi']);
        $this->createJsonCatalog('de', 'errors', ['error_b' => 'b', 'error_a' => 'a']);

        $compiler = new I18nCatalogCompiler();

        $result1 = $compiler->compile($this->catalogPath);
        $result2 = $compiler->compile($this->catalogPath);

        // Same output every time
        self::assertSame($result1->toArray(), $result2->toArray());
        self::assertSame($result1->totalHash, $result2->totalHash);

        // Locales must be sorted
        self::assertSame(['de', 'en', 'fr'], $result1->locales);

        // Keys within a domain must be sorted
        self::assertSame(['a_field', 'z_field'], $result1->index['fr']['validation']);
        self::assertSame(['goodbye', 'hello'], $result1->index['en']['messages']);
    }

    #[Test]
    public function emptyCatalogPathProducesEmptyIndex(): void
    {
        // catalogPath exists but contains no locale directories
        $compiler = new I18nCatalogCompiler();
        $index = $compiler->compile($this->catalogPath);

        self::assertSame([], $index->locales);
        self::assertSame([], $index->index);
        self::assertSame([], $index->fileHashes);
        self::assertNotEmpty($index->totalHash);
    }

    #[Test]
    public function nonExistentCatalogPathProducesEmptyIndex(): void
    {
        $compiler = new I18nCatalogCompiler();
        $index = $compiler->compile($this->catalogPath . '/nonexistent');

        self::assertSame([], $index->locales);
        self::assertSame([], $index->index);
    }

    #[Test]
    public function fileHashingForStalenessDetection(): void
    {
        $this->createJsonCatalog('en', 'messages', ['hello' => 'Hello']);

        $compiler = new I18nCatalogCompiler();
        $index = $compiler->compile($this->catalogPath);

        // Should have a file hash for the catalog file
        self::assertNotEmpty($index->fileHashes);

        $hashKeys = array_keys($index->fileHashes);
        // Hash key should use forward slashes
        foreach ($hashKeys as $key) {
            self::assertStringNotContainsString('\\', $key);
        }

        // Each hash should be a valid SHA-256 hex string
        foreach ($index->fileHashes as $hash) {
            self::assertSame(64, strlen($hash));
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        }
    }

    #[Test]
    public function multipleDomainsPerLocale(): void
    {
        $this->createJsonCatalog('en', 'messages', ['hello' => 'Hello']);
        $this->createJsonCatalog('en', 'validation', ['required' => 'Required']);
        $this->createJsonCatalog('en', 'errors', ['not_found' => 'Not found']);

        $compiler = new I18nCatalogCompiler();
        $index = $compiler->compile($this->catalogPath);

        self::assertSame(['en'], $index->locales);
        self::assertCount(3, $index->index['en']);

        // Domains should be sorted
        $domainKeys = array_keys($index->index['en']);
        self::assertSame(['errors', 'messages', 'validation'], $domainKeys);
    }

    /** @param array<string, string> $translations */
    private function createJsonCatalog(string $locale, string $domain, array $translations): void
    {
        $localeDir = $this->catalogPath . DIRECTORY_SEPARATOR . $locale;

        if (!is_dir($localeDir)) {
            mkdir($localeDir, 0o750, true);
        }

        file_put_contents(
            $localeDir . DIRECTORY_SEPARATOR . $domain . '.json',
            json_encode($translations, JSON_THROW_ON_ERROR),
        );
    }

    /** @param array<string, string> $translations */
    private function createPhpCatalog(string $locale, string $domain, array $translations): void
    {
        $localeDir = $this->catalogPath . DIRECTORY_SEPARATOR . $locale;

        if (!is_dir($localeDir)) {
            mkdir($localeDir, 0o750, true);
        }

        $exported = var_export($translations, true);
        file_put_contents(
            $localeDir . DIRECTORY_SEPARATOR . $domain . '.php',
            "<?php\nreturn " . $exported . ";\n",
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
