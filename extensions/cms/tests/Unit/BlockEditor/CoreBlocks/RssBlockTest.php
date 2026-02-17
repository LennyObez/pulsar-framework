<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\RssBlock;

#[CoversClass(RssBlock::class)]
final class RssBlockTest extends TestCase
{
    private RssBlock $block;

    protected function setUp(): void
    {
        $this->block = new RssBlock();
    }

    public function testType(): void
    {
        self::assertSame('rss', $this->block->type());
    }

    public function testRenderWithItems(): void
    {
        $html = $this->block->render([
            'feedUrl' => 'https://example.com/feed',
            'items' => [
                ['title' => 'Article', 'url' => 'https://example.com/1', 'description' => 'Desc', 'date' => '2026-01-01'],
            ],
            'showDescription' => true,
            'showDate' => true,
        ]);

        self::assertStringContainsString('data-feed-url="https://example.com/feed"', $html);
        self::assertStringContainsString('>Article</a>', $html);
        self::assertStringContainsString('2026-01-01', $html);
        self::assertStringContainsString('Desc', $html);
        self::assertStringContainsString('target="_blank"', $html);
    }

    public function testRenderHidesDescriptionWhenDisabled(): void
    {
        $html = $this->block->render([
            'feedUrl' => 'https://example.com/feed',
            'items' => [
                ['title' => 'A', 'url' => '/a', 'description' => 'Hidden'],
            ],
            'showDescription' => false,
        ]);

        self::assertStringNotContainsString('Hidden', $html);
    }

    public function testRenderEscapesXss(): void
    {
        $html = $this->block->render([
            'feedUrl' => '"><script>',
            'items' => [
                ['title' => '<script>alert(1)</script>', 'url' => '"><img src=x>'],
            ],
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testValidateRequiresFeedUrlAndItems(): void
    {
        $errors = $this->block->validate([]);
        self::assertContains('feedUrl is required and must be a string', $errors);
        self::assertContains('items is required and must be an array', $errors);
    }

    public function testValidateAcceptsValidData(): void
    {
        $errors = $this->block->validate([
            'feedUrl' => 'https://example.com/rss',
            'items' => [
                ['title' => 'Post', 'url' => 'https://example.com/1'],
            ],
        ]);

        self::assertSame([], $errors);
    }
}
