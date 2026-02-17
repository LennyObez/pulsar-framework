<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Seo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Seo\MetaTagCollection;

#[CoversClass(MetaTagCollection::class)]
final class MetaTagCollectionTest extends TestCase
{
    #[Test]
    public function toHtml_renders_title(): void
    {
        $meta = new MetaTagCollection(title: 'My Page');

        self::assertStringContainsString('<title>My Page</title>', $meta->toHtml());
    }

    #[Test]
    public function toHtml_renders_description(): void
    {
        $meta = new MetaTagCollection(description: 'A great page');

        self::assertStringContainsString('name="description"', $meta->toHtml());
        self::assertStringContainsString('content="A great page"', $meta->toHtml());
    }

    #[Test]
    public function toHtml_renders_canonical(): void
    {
        $meta = new MetaTagCollection(canonical: 'https://example.com/page');

        self::assertStringContainsString('rel="canonical"', $meta->toHtml());
        self::assertStringContainsString('href="https://example.com/page"', $meta->toHtml());
    }

    #[Test]
    public function toHtml_renders_robots(): void
    {
        $meta = new MetaTagCollection(robots: 'noindex, nofollow');

        self::assertStringContainsString('name="robots"', $meta->toHtml());
        self::assertStringContainsString('content="noindex, nofollow"', $meta->toHtml());
    }

    #[Test]
    public function toHtml_renders_og_tags(): void
    {
        $meta = new MetaTagCollection(ogTags: [
            'og:title' => 'OG Title',
            'og:image' => 'https://example.com/img.jpg',
        ]);

        $html = $meta->toHtml();
        self::assertStringContainsString('property="og:title"', $html);
        self::assertStringContainsString('content="OG Title"', $html);
        self::assertStringContainsString('property="og:image"', $html);
    }

    #[Test]
    public function toHtml_renders_twitter_cards(): void
    {
        $meta = new MetaTagCollection(twitterCards: [
            'twitter:card' => 'summary_large_image',
        ]);

        $html = $meta->toHtml();
        self::assertStringContainsString('name="twitter:card"', $html);
        self::assertStringContainsString('content="summary_large_image"', $html);
    }

    #[Test]
    public function toHtml_renders_hreflang_links(): void
    {
        $meta = new MetaTagCollection(hreflangLinks: [
            'en' => 'https://example.com/en',
            'de' => 'https://example.com/de',
        ]);

        $html = $meta->toHtml();
        self::assertStringContainsString('hreflang="en"', $html);
        self::assertStringContainsString('hreflang="de"', $html);
    }

    #[Test]
    public function toHtml_escapes_special_characters(): void
    {
        $meta = new MetaTagCollection(title: 'Title with "quotes" & <tags>');

        $html = $meta->toHtml();
        self::assertStringContainsString('&quot;quotes&quot;', $html);
        self::assertStringContainsString('&amp;', $html);
        self::assertStringContainsString('&lt;tags&gt;', $html);
    }

    #[Test]
    public function toHtml_empty_collection_returns_empty_string(): void
    {
        $meta = new MetaTagCollection();

        self::assertSame('', $meta->toHtml());
    }
}
