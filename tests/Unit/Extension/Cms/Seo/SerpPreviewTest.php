<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Seo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Seo\SerpPreview;

#[CoversClass(SerpPreview::class)]
final class SerpPreviewTest extends TestCase
{
    #[Test]
    public function create_preserves_original_values(): void
    {
        $preview = SerpPreview::create('My Title', 'My description', 'https://example.com/page');

        self::assertSame('My Title', $preview->title);
        self::assertSame('My description', $preview->metaDescription);
        self::assertSame('https://example.com/page', $preview->url);
    }

    #[Test]
    public function title_not_truncated_when_under_limit(): void
    {
        $title = str_repeat('a', 60);
        $preview = SerpPreview::create($title, 'desc', 'https://example.com');

        self::assertSame($title, $preview->displayTitle);
    }

    #[Test]
    public function title_truncated_when_over_60_chars(): void
    {
        $title = str_repeat('a', 61);
        $preview = SerpPreview::create($title, 'desc', 'https://example.com');

        self::assertSame(str_repeat('a', 57) . '...', $preview->displayTitle);
        self::assertSame(60, mb_strlen($preview->displayTitle));
    }

    #[Test]
    public function description_not_truncated_when_under_limit(): void
    {
        $desc = str_repeat('b', 160);
        $preview = SerpPreview::create('Title', $desc, 'https://example.com');

        self::assertSame($desc, $preview->displayDescription);
    }

    #[Test]
    public function description_truncated_when_over_160_chars(): void
    {
        $desc = str_repeat('b', 161);
        $preview = SerpPreview::create('Title', $desc, 'https://example.com');

        self::assertSame(str_repeat('b', 157) . '...', $preview->displayDescription);
        self::assertSame(160, mb_strlen($preview->displayDescription));
    }

    #[Test]
    public function strips_https_protocol_from_url(): void
    {
        $preview = SerpPreview::create('T', 'D', 'https://example.com/page');

        self::assertSame('example.com/page', $preview->displayUrl);
    }

    #[Test]
    public function strips_http_protocol_from_url(): void
    {
        $preview = SerpPreview::create('T', 'D', 'http://example.com/page');

        self::assertSame('example.com/page', $preview->displayUrl);
    }

    #[Test]
    public function url_without_protocol_unchanged(): void
    {
        $preview = SerpPreview::create('T', 'D', 'example.com/page');

        self::assertSame('example.com/page', $preview->displayUrl);
    }

    #[Test]
    public function to_array_returns_expected_shape(): void
    {
        $preview = SerpPreview::create('Title', 'Description', 'https://example.com');

        $array = $preview->toArray();

        self::assertSame('Title', $array['title']);
        self::assertSame('Description', $array['description']);
        self::assertSame('https://example.com', $array['url']);
        self::assertSame('Title', $array['display_title']);
        self::assertSame('Description', $array['display_description']);
        self::assertSame('example.com', $array['display_url']);
    }
}
