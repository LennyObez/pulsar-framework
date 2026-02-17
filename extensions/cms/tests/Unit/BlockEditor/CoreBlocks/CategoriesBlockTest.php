<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\CategoriesBlock;

#[CoversClass(CategoriesBlock::class)]
final class CategoriesBlockTest extends TestCase
{
    private CategoriesBlock $block;

    protected function setUp(): void
    {
        $this->block = new CategoriesBlock();
    }

    public function testType(): void
    {
        self::assertSame('categories', $this->block->type());
    }

    public function testRenderListDisplay(): void
    {
        $html = $this->block->render([
            'categories' => [
                ['name' => 'News', 'url' => '/news', 'count' => 12],
                ['name' => 'Tech', 'url' => '/tech', 'count' => 8],
            ],
            'display' => 'list',
            'showCounts' => true,
        ]);

        self::assertStringContainsString('<ul class="categories-block">', $html);
        self::assertStringContainsString('href="/news"', $html);
        self::assertStringContainsString('>News</a>', $html);
        self::assertStringContainsString('(12)', $html);
    }

    public function testRenderDropdownDisplay(): void
    {
        $html = $this->block->render([
            'categories' => [
                ['name' => 'News', 'url' => '/news', 'count' => 5],
            ],
            'display' => 'dropdown',
        ]);

        self::assertStringContainsString('<select', $html);
        self::assertStringContainsString('value="/news"', $html);
        self::assertStringContainsString('News (5)', $html);
    }

    public function testRenderHidesCountsWhenDisabled(): void
    {
        $html = $this->block->render([
            'categories' => [
                ['name' => 'News', 'url' => '/news', 'count' => 12],
            ],
            'showCounts' => false,
        ]);

        self::assertStringNotContainsString('(12)', $html);
    }

    public function testRenderEscapesXss(): void
    {
        $html = $this->block->render([
            'categories' => [
                ['name' => '<script>xss</script>', 'url' => '"><img src=x>', 'count' => 1],
            ],
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testValidateRequiresCategories(): void
    {
        $errors = $this->block->validate([]);
        self::assertContains('categories is required and must be an array', $errors);
    }

    public function testValidateRejectsInvalidDisplay(): void
    {
        $errors = $this->block->validate([
            'categories' => [],
            'display' => 'table',
        ]);

        self::assertNotEmpty($errors);
    }

    public function testValidateAcceptsValidData(): void
    {
        $errors = $this->block->validate([
            'categories' => [
                ['name' => 'News', 'url' => '/news'],
            ],
        ]);

        self::assertSame([], $errors);
    }
}
