<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\SpacerBlock;

#[CoversClass(SpacerBlock::class)]
final class SpacerBlockTest extends TestCase
{
    private SpacerBlock $block;

    protected function setUp(): void
    {
        $this->block = new SpacerBlock();
    }

    #[Test]
    public function typeReturnsSpacer(): void
    {
        self::assertSame('spacer', $this->block->type());
    }

    #[Test]
    public function renderOutputsDivWithHeight(): void
    {
        $html = $this->block->render(['height' => 50]);

        self::assertSame('<div style="height:50px" aria-hidden="true"></div>', $html);
    }

    #[Test]
    public function renderClampsHeightToMinimum(): void
    {
        $html = $this->block->render(['height' => -10]);

        self::assertStringContainsString('height:1px', $html);
    }

    #[Test]
    public function renderClampsHeightToMaximum(): void
    {
        $html = $this->block->render(['height' => 999]);

        self::assertStringContainsString('height:500px', $html);
    }

    #[Test]
    public function validateReturnsErrorForMissingHeight(): void
    {
        $errors = $this->block->validate([]);

        self::assertStringContainsString('height is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForOutOfRangeHeight(): void
    {
        $errors = $this->block->validate(['height' => 501]);

        self::assertStringContainsString('between 1 and 500', $errors[0]);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        self::assertSame([], $this->block->validate(['height' => 100]));
    }
}
