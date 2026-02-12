<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Catalog;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Catalog\JsonCatalog;

use function file_put_contents;
use function glob;
use function is_dir;
use function json_encode;
use function mkdir;
use function rmdir;
use function unlink;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

#[CoversClass(JsonCatalog::class)]
final class JsonCatalogTest extends TestCase
{
    private string $catalogPath;

    protected function setUp(): void
    {
        $this->catalogPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_test_catalog_json_' . uniqid();
        mkdir($this->catalogPath . DIRECTORY_SEPARATOR . 'en', 0o777, true);

        $data = [
            'welcome' => 'Welcome!',
            'greeting' => 'Hello, {name}!',
            'terms' => [
                'message' => 'Agree to <a>Terms</a>.',
                'html_safe' => true,
                'context' => 'Footer',
                'max_length' => 100,
            ],
        ];

        file_put_contents(
            $this->catalogPath . DIRECTORY_SEPARATOR . 'en' . DIRECTORY_SEPARATOR . 'messages.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->catalogPath);
    }

    #[Test]
    public function getReturnsEntryForExistingKey(): void
    {
        $catalog = new JsonCatalog($this->catalogPath);

        $entry = $catalog->get('welcome', 'en');

        self::assertNotNull($entry);
        self::assertSame('Welcome!', $entry->message);
    }

    #[Test]
    public function getReturnsRichEntry(): void
    {
        $catalog = new JsonCatalog($this->catalogPath);

        $entry = $catalog->get('terms', 'en');

        self::assertNotNull($entry);
        self::assertTrue($entry->htmlSafe);
        self::assertSame('Footer', $entry->context);
        self::assertSame(100, $entry->maxLength);
    }

    #[Test]
    public function getReturnsNullForMissingKey(): void
    {
        $catalog = new JsonCatalog($this->catalogPath);

        self::assertNull($catalog->get('nonexistent', 'en'));
    }

    #[Test]
    public function hasReturnsTrueForExistingKey(): void
    {
        $catalog = new JsonCatalog($this->catalogPath);

        self::assertTrue($catalog->has('welcome', 'en'));
    }

    #[Test]
    public function allReturnsAllEntries(): void
    {
        $catalog = new JsonCatalog($this->catalogPath);

        $entries = $catalog->all('en');

        self::assertCount(3, $entries);
    }

    #[Test]
    public function returnsEmptyForMissingLocale(): void
    {
        $catalog = new JsonCatalog($this->catalogPath);

        self::assertSame([], $catalog->all('de'));
    }

    #[Test]
    public function negativeCacheForMissingDomain(): void
    {
        $catalog = new JsonCatalog($this->catalogPath);

        self::assertNull($catalog->get('key', 'de'));
        self::assertNull($catalog->get('key', 'de'));
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
