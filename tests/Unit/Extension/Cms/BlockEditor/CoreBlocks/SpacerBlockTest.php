<?php

declare(strict_types=1);

namespace Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

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
    public function rendersDivWithHeight(): void
    {
        $html = $this->block->render(['height' => 50]);

        self::assertSame('<div style="height:50px" aria-hidden="true"></div>', $html);
    }

    #[Test]
    public function clampsHeightToMinimum(): void
    {
        $html = $this->block->render(['height' => 0]);

        self::assertSame('<div style="height:1px" aria-hidden="true"></div>', $html);
    }

    #[Test]
    public function clampsHeightToMaximum(): void
    {
        $html = $this->block->render(['height' => 999]);

        self::assertSame('<div style="height:500px" aria-hidden="true"></div>', $html);
    }

    #[Test]
    public function validatesMissingHeight(): void
    {
        $errors = $this->block->validate([]);

        self::assertContains('height is required and must be an integer', $errors);
    }

    #[Test]
    public function validatesHeightMustBeInteger(): void
    {
        $errors = $this->block->validate(['height' => 'tall']);

        self::assertContains('height is required and must be an integer', $errors);
    }

    #[Test]
    public function validatesHeightBelowRange(): void
    {
        $errors = $this->block->validate(['height' => 0]);

        self::assertContains('height must be between 1 and 500', $errors);
    }

    #[Test]
    public function validatesHeightAboveRange(): void
    {
        $errors = $this->block->validate(['height' => 501]);

        self::assertContains('height must be between 1 and 500', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate(['height' => 100]);

        self::assertSame([], $errors);
    }
}
