<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Catalog;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Catalog\PhpCatalog;

use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function rmdir;
use function unlink;

#[CoversClass(PhpCatalog::class)]
final class PhpCatalogTest extends TestCase
{
    private string $catalogPath;

    protected function setUp(): void
    {
        $this->catalogPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_test_catalog_php_' . uniqid();
        mkdir($this->catalogPath . DIRECTORY_SEPARATOR . 'en', 0o777, true);
        mkdir($this->catalogPath . DIRECTORY_SEPARATOR . 'fr', 0o777, true);

        file_put_contents(
            $this->catalogPath . DIRECTORY_SEPARATOR . 'en' . DIRECTORY_SEPARATOR . 'messages.php',
            "<?php\nreturn [\n    'welcome' => 'Welcome!',\n    'greeting' => 'Hello, {name}!',\n    'terms' => [\n        'message' => 'Agree to <a>Terms</a>.',\n        'html_safe' => true,\n        'context' => 'Footer',\n        'max_length' => 100,\n    ],\n];\n",
        );

        file_put_contents(
            $this->catalogPath . DIRECTORY_SEPARATOR . 'fr' . DIRECTORY_SEPARATOR . 'messages.php',
            "<?php\nreturn [\n    'welcome' => 'Bienvenue!',\n];\n",
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->catalogPath);
    }

    #[Test]
    public function getReturnsEntryForExistingKey(): void
    {
        $catalog = new PhpCatalog($this->catalogPath);

        $entry = $catalog->get('welcome', 'en');

        self::assertNotNull($entry);
        self::assertSame('welcome', $entry->key);
        self::assertSame('Welcome!', $entry->message);
        self::assertFalse($entry->htmlSafe);
    }

    #[Test]
    public function getReturnsRichEntry(): void
    {
        $catalog = new PhpCatalog($this->catalogPath);

        $entry = $catalog->get('terms', 'en');

        self::assertNotNull($entry);
        self::assertSame('Agree to <a>Terms</a>.', $entry->message);
        self::assertTrue($entry->htmlSafe);
        self::assertSame('Footer', $entry->context);
        self::assertSame(100, $entry->maxLength);
    }

    #[Test]
    public function getReturnsNullForMissingKey(): void
    {
        $catalog = new PhpCatalog($this->catalogPath);

        self::assertNull($catalog->get('nonexistent', 'en'));
    }

    #[Test]
    public function getReturnsNullForMissingLocale(): void
    {
        $catalog = new PhpCatalog($this->catalogPath);

        self::assertNull($catalog->get('welcome', 'de'));
    }

    #[Test]
    public function hasReturnsTrueForExistingKey(): void
    {
        $catalog = new PhpCatalog($this->catalogPath);

        self::assertTrue($catalog->has('welcome', 'en'));
    }

    #[Test]
    public function hasReturnsFalseForMissingKey(): void
    {
        $catalog = new PhpCatalog($this->catalogPath);

        self::assertFalse($catalog->has('nonexistent', 'en'));
    }

    #[Test]
    public function allReturnsAllEntriesForDomain(): void
    {
        $catalog = new PhpCatalog($this->catalogPath);

        $entries = $catalog->all('en');

        self::assertCount(3, $entries);
        self::assertArrayHasKey('welcome', $entries);
        self::assertArrayHasKey('greeting', $entries);
        self::assertArrayHasKey('terms', $entries);
    }

    #[Test]
    public function cachesLoadedDomain(): void
    {
        $catalog = new PhpCatalog($this->catalogPath);

        $first = $catalog->get('welcome', 'en');
        $second = $catalog->get('greeting', 'en');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame('Welcome!', $first->message);
        self::assertSame('Hello, {name}!', $second->message);
    }

    #[Test]
    public function negativeCacheForMissingDomain(): void
    {
        $catalog = new PhpCatalog($this->catalogPath);

        // First call: misses
        self::assertNull($catalog->get('key', 'de'));
        // Second call: negative cache hit (no filesystem probe)
        self::assertNull($catalog->get('key', 'de'));
    }

    #[Test]
    public function returnsEmptyForMissingDomainFile(): void
    {
        $catalog = new PhpCatalog($this->catalogPath);

        $entries = $catalog->all('en', 'errors');

        self::assertSame([], $entries);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*');

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->removeDir($file);
            } else {
                unlink($file);
            }
        }

        rmdir($dir);
    }
}
