<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Seo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Seo\JsonLdCollection;

use function substr_count;

#[CoversClass(JsonLdCollection::class)]
final class JsonLdCollectionTest extends TestCase
{
    #[Test]
    public function toScript_returns_empty_string_for_no_items(): void
    {
        $collection = new JsonLdCollection();

        self::assertSame('', $collection->toScript());
    }

    #[Test]
    public function toScript_renders_single_item_directly(): void
    {
        $item = ['@type' => 'Article', 'name' => 'Test'];
        $collection = new JsonLdCollection([$item]);

        $script = $collection->toScript();

        self::assertStringStartsWith('<script type="application/ld+json">', $script);
        self::assertStringEndsWith('</script>', $script);
        self::assertStringContainsString('"@type":"Article"', $script);
        self::assertStringNotContainsString('@graph', $script);
    }

    #[Test]
    public function toScript_wraps_multiple_items_in_graph(): void
    {
        $items = [
            ['@type' => 'Article', 'name' => 'Article 1'],
            ['@type' => 'Organization', 'name' => 'Org'],
        ];
        $collection = new JsonLdCollection($items);

        $script = $collection->toScript();

        self::assertStringContainsString('@graph', $script);
        self::assertStringContainsString('"Article"', $script);
        self::assertStringContainsString('"Organization"', $script);
    }

    #[Test]
    public function toScript_uses_unescaped_slashes(): void
    {
        $item = ['@type' => 'Article', 'url' => 'https://example.com/page'];
        $collection = new JsonLdCollection([$item]);

        $script = $collection->toScript();

        self::assertStringContainsString('https://example.com/page', $script);
        self::assertStringNotContainsString('\/', $script);
    }

    /**
     * JSON-LD is rendered inside a <script> block, so a "</script>" smuggled
     * into a CMS-controlled value (here the article name) must never appear
     * literally — it would close the block early and turn the payload into
     * live HTML (stored XSS). JSON_HEX_TAG hex-escapes every "<" and ">".
     */
    #[Test]
    public function toScript_escapes_script_closing_tag_in_user_content(): void
    {
        $item = [
            '@type' => 'Article',
            'name' => 'Pwned</script><script>alert(document.cookie)</script>',
        ];
        $collection = new JsonLdCollection([$item]);

        $script = $collection->toScript();

        // No literal angle brackets from the payload survive: the only "<"/">"
        // in the output are the ones framing our own outer <script> wrapper.
        self::assertStringNotContainsString('</script><script>', $script);
        self::assertStringNotContainsString('<script>alert', $script);
        // The content is preserved, just hex-escaped.
        self::assertStringContainsString('<', $script);
        self::assertStringContainsString('>', $script);
        // Exactly one opening and one closing tag — our wrapper, nothing injected.
        self::assertSame(1, substr_count($script, '<script'));
        self::assertSame(1, substr_count($script, '</script>'));
    }
}
