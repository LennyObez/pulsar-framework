<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\ArchivesBlock;

#[CoversClass(ArchivesBlock::class)]
final class ArchivesBlockTest extends TestCase
{
    private ArchivesBlock $block;

    protected function setUp(): void
    {
        $this->block = new ArchivesBlock();
    }

    public function testType(): void
    {
        self::assertSame('archives', $this->block->type());
    }

    public function testRenderListDisplay(): void
    {
        $html = $this->block->render([
            'archives' => [
                ['label' => 'March 2026', 'url' => '/2026/03', 'count' => 5],
            ],
            'display' => 'list',
            'showCounts' => true,
        ]);

        self::assertStringContainsString('<ul class="archives-block">', $html);
        self::assertStringContainsString('March 2026</a>', $html);
        self::assertStringContainsString('(5)', $html);
    }

    public function testRenderDropdownDisplay(): void
    {
        $html = $this->block->render([
            'archives' => [
                ['label' => 'January 2026', 'url' => '/2026/01', 'count' => 3],
            ],
            'display' => 'dropdown',
        ]);

        self::assertStringContainsString('<select', $html);
        self::assertStringContainsString('value="/2026/01"', $html);
    }

    public function testRenderHidesCountsWhenDisabled(): void
    {
        $html = $this->block->render([
            'archives' => [
                ['label' => 'Jan', 'url' => '/jan', 'count' => 10],
            ],
            'showCounts' => false,
        ]);

        self::assertStringNotContainsString('(10)', $html);
    }

    public function testValidateRequiresArchives(): void
    {
        $errors = $this->block->validate([]);
        self::assertContains('archives is required and must be an array', $errors);
    }

    public function testValidateRejectsInvalidGroupBy(): void
    {
        $errors = $this->block->validate([
            'archives' => [],
            'groupBy' => 'weekly',
        ]);

        self::assertNotEmpty($errors);
    }

    public function testValidateAcceptsValidData(): void
    {
        $errors = $this->block->validate([
            'archives' => [
                ['label' => 'March 2026', 'url' => '/2026/03'],
            ],
        ]);

        self::assertSame([], $errors);
    }
}
