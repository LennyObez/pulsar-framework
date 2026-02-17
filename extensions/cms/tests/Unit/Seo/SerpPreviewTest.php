<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Seo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Seo\SerpPreview;

#[CoversClass(SerpPreview::class)]
final class SerpPreviewTest extends TestCase
{
    #[Test]
    public function create_stores_original_values(): void
    {
        $preview = SerpPreview::create('My Title', 'My description text', 'https://example.com/page');

        self::assertSame('My Title', $preview->title);
        self::assertSame('My description text', $preview->metaDescription);
        self::assertSame('https://example.com/page', $preview->url);
    }

    #[Test]
    public function create_truncates_long_title_at_60_chars(): void
    {
        $longTitle = str_repeat('A', 70);
        $preview = SerpPreview::create($longTitle, 'desc', 'https://example.com');

        self::assertSame(60, mb_strlen($preview->displayTitle));
        self::assertStringEndsWith('...', $preview->displayTitle);
    }

    #[Test]
    public function create_keeps_short_title_unchanged(): void
    {
        $shortTitle = 'Short Title';
        $preview = SerpPreview::create($shortTitle, 'desc', 'https://example.com');

        self::assertSame($shortTitle, $preview->displayTitle);
    }

    #[Test]
    public function create_truncates_long_description_at_160_chars(): void
    {
        $longDesc = str_repeat('B', 200);
        $preview = SerpPreview::create('Title', $longDesc, 'https://example.com');

        self::assertSame(160, mb_strlen($preview->displayDescription));
        self::assertStringEndsWith('...', $preview->displayDescription);
    }

    #[Test]
    public function create_keeps_short_description_unchanged(): void
    {
        $shortDesc = 'Short description';
        $preview = SerpPreview::create('Title', $shortDesc, 'https://example.com');

        self::assertSame($shortDesc, $preview->displayDescription);
    }

    #[Test]
    public function create_strips_protocol_from_display_url(): void
    {
        $preview = SerpPreview::create('Title', 'Desc', 'https://example.com/page');

        self::assertSame('example.com/page', $preview->displayUrl);
    }

    #[Test]
    public function create_strips_http_protocol_from_display_url(): void
    {
        $preview = SerpPreview::create('Title', 'Desc', 'http://example.com/page');

        self::assertSame('example.com/page', $preview->displayUrl);
    }

    #[Test]
    public function toArray_returns_all_fields(): void
    {
        $preview = SerpPreview::create('Title', 'Desc', 'https://example.com');
        $array = $preview->toArray();

        self::assertSame('Title', $array['title']);
        self::assertSame('Desc', $array['description']);
        self::assertSame('https://example.com', $array['url']);
        self::assertSame('Title', $array['display_title']);
        self::assertSame('Desc', $array['display_description']);
        self::assertSame('example.com', $array['display_url']);
    }
}
