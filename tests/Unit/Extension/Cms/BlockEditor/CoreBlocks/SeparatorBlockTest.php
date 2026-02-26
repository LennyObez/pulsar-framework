<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\SeparatorBlock;

#[CoversClass(SeparatorBlock::class)]
final class SeparatorBlockTest extends TestCase
{
    private SeparatorBlock $block;

    protected function setUp(): void
    {
        $this->block = new SeparatorBlock();
    }

    #[Test]
    public function typeReturnsSeparator(): void
    {
        self::assertSame('separator', $this->block->type());
    }

    #[Test]
    public function rendersHrTag(): void
    {
        $html = $this->block->render([]);

        self::assertSame('<hr class="separator">', $html);
    }

    #[Test]
    public function rendersWithValidStyleClass(): void
    {
        $html = $this->block->render(['style' => 'dashed']);

        self::assertSame('<hr class="separator separator--dashed">', $html);
    }

    #[Test]
    public function ignoresInvalidStyle(): void
    {
        $html = $this->block->render(['style' => 'rainbow']);

        self::assertSame('<hr class="separator">', $html);
    }

    #[Test]
    public function validatesInvalidStyleString(): void
    {
        $errors = $this->block->validate(['style' => 'rainbow']);

        self::assertContains('style must be one of: solid, dashed, dotted, wide', $errors);
    }

    #[Test]
    public function validatesStyleMustBeString(): void
    {
        $errors = $this->block->validate(['style' => 123]);

        self::assertContains('style must be a string', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate(['style' => 'solid']);

        self::assertSame([], $errors);
    }

    #[Test]
    public function emptyDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate([]);

        self::assertSame([], $errors);
    }
}
