<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\PageListBlock;

#[CoversClass(PageListBlock::class)]
final class PageListBlockTest extends TestCase
{
    private PageListBlock $block;

    protected function setUp(): void
    {
        $this->block = new PageListBlock();
    }

    public function testType(): void
    {
        self::assertSame('page-list', $this->block->type());
    }

    public function testRenderFlatList(): void
    {
        $html = $this->block->render([
            'pages' => [
                ['title' => 'About', 'url' => '/about'],
                ['title' => 'Contact', 'url' => '/contact'],
            ],
            'showHierarchy' => false,
        ]);

        self::assertStringContainsString('aria-label="Page list"', $html);
        self::assertStringContainsString('href="/about"', $html);
        self::assertStringContainsString('href="/contact"', $html);
    }

    public function testRenderHierarchicalList(): void
    {
        $html = $this->block->render([
            'pages' => [
                [
                    'title' => 'Docs',
                    'url' => '/docs',
                    'children' => [
                        ['title' => 'Install', 'url' => '/docs/install'],
                    ],
                ],
            ],
        ]);

        self::assertStringContainsString('href="/docs/install"', $html);
        // Should have nested ul
        self::assertGreaterThan(1, substr_count($html, '<ul'));
    }

    public function testRenderEscapesXss(): void
    {
        $html = $this->block->render([
            'pages' => [
                ['title' => '<script>xss</script>', 'url' => '"><img>'],
            ],
        ]);

        self::assertStringNotContainsString('<script>', $html);
    }

    public function testValidateRequiresPages(): void
    {
        $errors = $this->block->validate([]);
        self::assertContains('pages is required and must be an array', $errors);
    }

    public function testValidateAcceptsValidData(): void
    {
        $errors = $this->block->validate([
            'pages' => [
                ['title' => 'Home', 'url' => '/'],
            ],
        ]);

        self::assertSame([], $errors);
    }
}
