<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Seo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Seo\JsonLdCollection;

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
}
