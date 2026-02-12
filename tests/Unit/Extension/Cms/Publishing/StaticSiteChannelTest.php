<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Publishing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Internal\Publishing\StaticSiteChannel;

use function is_dir;

use const DIRECTORY_SEPARATOR;

#[CoversClass(StaticSiteChannel::class)]
final class StaticSiteChannelTest extends TestCase
{
    private string $outputPath;

    protected function setUp(): void
    {
        $this->outputPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_static_test_' . bin2hex(random_bytes(4));
        mkdir($this->outputPath, 0o755, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->outputPath);
    }

    #[Test]
    public function name_returns_static(): void
    {
        $channel = new StaticSiteChannel($this->outputPath);
        self::assertSame('static', $channel->name());
    }

    #[Test]
    public function is_disabled_by_default(): void
    {
        $channel = new StaticSiteChannel($this->outputPath, enabled: false);
        self::assertFalse($channel->isEnabled());
    }

    #[Test]
    public function is_enabled_when_configured(): void
    {
        $channel = new StaticSiteChannel($this->outputPath, enabled: true);
        self::assertTrue($channel->isEnabled());
    }

    #[Test]
    public function publish_creates_html_file_at_correct_path(): void
    {
        $channel = new StaticSiteChannel($this->outputPath, enabled: true);
        $content = $this->createContent();
        $translation = $this->createTranslation('blog/hello-world');

        $result = $channel->publish($content, $translation);

        self::assertTrue($result->success);
        self::assertSame('static', $result->channelName);

        $expectedPath = $this->outputPath . DIRECTORY_SEPARATOR . 'en' . DIRECTORY_SEPARATOR . 'blog/hello-world' . DIRECTORY_SEPARATOR . 'index.html';
        self::assertFileExists($expectedPath);

        $html = file_get_contents($expectedPath);
        self::assertIsString($html);
        self::assertStringContainsString('<title>Hello World</title>', $html);
        self::assertStringContainsString('<p>Hello</p>', $html);
        self::assertStringContainsString('lang="en"', $html);
    }

    #[Test]
    public function publish_creates_directory_structure(): void
    {
        $channel = new StaticSiteChannel($this->outputPath, enabled: true);
        $content = $this->createContent();
        $translation = $this->createTranslation('docs/getting-started/installation');

        $result = $channel->publish($content, $translation);

        self::assertTrue($result->success);
        self::assertTrue(is_dir(
            $this->outputPath . DIRECTORY_SEPARATOR . 'en' . DIRECTORY_SEPARATOR . 'docs/getting-started/installation',
        ));
    }

    #[Test]
    public function remove_static_file_deletes_file(): void
    {
        $channel = new StaticSiteChannel($this->outputPath, enabled: true);
        $content = $this->createContent();
        $translation = $this->createTranslation('blog/hello-world');

        // First publish to create the file
        $channel->publish($content, $translation);

        $expectedPath = $this->outputPath . DIRECTORY_SEPARATOR . 'en' . DIRECTORY_SEPARATOR . 'blog/hello-world' . DIRECTORY_SEPARATOR . 'index.html';
        self::assertFileExists($expectedPath);

        // Now remove it
        $result = $channel->removeStaticFile($translation);

        self::assertTrue($result->success);
        self::assertFileDoesNotExist($expectedPath);
    }

    #[Test]
    public function unpublish_returns_success(): void
    {
        $channel = new StaticSiteChannel($this->outputPath, enabled: true);
        $content = $this->createContent();

        $result = $channel->unpublish($content);

        self::assertTrue($result->success);
        self::assertSame('static', $result->channelName);
    }

    private function createContent(): Content
    {
        return Content::create(
            id: '01912345-6789-7abc-8def-0123456789ab',
            contentType: ContentType::Article,
            authorId: '01912345-6789-7abc-8def-0123456789cd',
        );
    }

    private function createTranslation(string $path): ContentTranslation
    {
        return new ContentTranslation(
            id: '01912345-0000-7abc-8def-aaaaaaaaaaaa',
            contentId: '01912345-6789-7abc-8def-0123456789ab',
            locale: 'en',
            title: 'Hello World',
            slugSegment: 'hello-world',
            path: $path,
            body: '<p>Hello</p>',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: 1,
            bodyPlaintext: 'Hello',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );
    }

    private function removeDir(string $dir): void
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
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
