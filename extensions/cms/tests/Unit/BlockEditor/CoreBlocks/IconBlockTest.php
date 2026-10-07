<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\IconBlock;

#[CoversClass(IconBlock::class)]
final class IconBlockTest extends TestCase
{
    private IconBlock $block;

    protected function setUp(): void
    {
        $this->block = new IconBlock();
    }

    #[Test]
    public function typeReturnsIcon(): void
    {
        self::assertSame('icon', $this->block->type());
    }

    #[Test]
    public function renderOutputsSpanWithIconClasses(): void
    {
        $html = $this->block->render(['name' => 'home']);

        self::assertStringContainsString('icon icon-home icon--md', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
    }

    #[Test]
    public function renderUsesValidSize(): void
    {
        $html = $this->block->render([
            'name' => 'star',
            'size' => 'lg',
        ]);

        self::assertStringContainsString('icon--lg', $html);
    }

    #[Test]
    public function renderDefaultsToMdForInvalidSize(): void
    {
        $html = $this->block->render([
            'name' => 'star',
            'size' => 'huge',
        ]);

        self::assertStringContainsString('icon--md', $html);
    }

    #[Test]
    public function renderAppliesCustomColor(): void
    {
        $html = $this->block->render([
            'name' => 'heart',
            'color' => '#ff0000',
        ]);

        self::assertStringContainsString('style="color:#ff0000"', $html);
    }

    #[Test]
    public function renderOmitsStyleWhenNoColor(): void
    {
        $html = $this->block->render(['name' => 'gear']);

        self::assertStringNotContainsString('style=', $html);
    }

    #[Test]
    public function validateReturnsErrorForMissingName(): void
    {
        $errors = $this->block->validate([]);

        self::assertStringContainsString('name is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForInvalidSize(): void
    {
        $errors = $this->block->validate([
            'name' => 'star',
            'size' => 'huge',
        ]);

        self::assertStringContainsString('size must be one of', $errors[0]);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        self::assertSame([], $this->block->validate([
            'name' => 'star',
            'size' => 'xl',
        ]));
    }
}
