<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\CompareBlock;

#[CoversClass(CompareBlock::class)]
final class CompareBlockTest extends TestCase
{
    private CompareBlock $block;

    protected function setUp(): void
    {
        $this->block = new CompareBlock();
    }

    #[Test]
    public function typeReturnsCompare(): void
    {
        self::assertSame('compare', $this->block->type());
    }

    #[Test]
    public function renderOutputsCustomElement(): void
    {
        $html = $this->block->render([
            'beforeImage' => ['src' => '/before.jpg', 'alt' => 'Before'],
            'afterImage' => ['src' => '/after.jpg', 'alt' => 'After'],
        ]);

        self::assertStringContainsString('cms-image-compare', $html);
        self::assertStringContainsString('data-before-src="/before.jpg"', $html);
        self::assertStringContainsString('data-after-src="/after.jpg"', $html);
    }

    #[Test]
    public function renderUsesDefaultLabels(): void
    {
        $html = $this->block->render([
            'beforeImage' => ['src' => '/b.jpg', 'alt' => 'B'],
            'afterImage' => ['src' => '/a.jpg', 'alt' => 'A'],
        ]);

        self::assertStringContainsString('data-before-label="Before"', $html);
        self::assertStringContainsString('data-after-label="After"', $html);
    }

    #[Test]
    public function renderSupportsCustomLabels(): void
    {
        $html = $this->block->render([
            'beforeImage' => ['src' => '/b.jpg', 'alt' => 'B'],
            'afterImage' => ['src' => '/a.jpg', 'alt' => 'A'],
            'beforeLabel' => 'Old',
            'afterLabel' => 'New',
        ]);

        self::assertStringContainsString('data-before-label="Old"', $html);
        self::assertStringContainsString('data-after-label="New"', $html);
    }

    #[Test]
    public function renderIncludesCaptionInFigure(): void
    {
        $html = $this->block->render([
            'beforeImage' => ['src' => '/b.jpg', 'alt' => 'B'],
            'afterImage' => ['src' => '/a.jpg', 'alt' => 'A'],
            'caption' => 'Renovation results',
        ]);

        self::assertStringContainsString('<figure>', $html);
        self::assertStringContainsString('Renovation results', $html);
    }

    #[Test]
    public function validateReturnsErrorsForMissingImages(): void
    {
        $errors = $this->block->validate([]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('beforeImage is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorsForMissingSrcAndAlt(): void
    {
        $errors = $this->block->validate([
            'beforeImage' => ['src' => 123],
            'afterImage' => ['alt' => 'A'],
        ]);

        self::assertNotEmpty($errors);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        $errors = $this->block->validate([
            'beforeImage' => ['src' => '/b.jpg', 'alt' => 'Before'],
            'afterImage' => ['src' => '/a.jpg', 'alt' => 'After'],
        ]);

        self::assertSame([], $errors);
    }
}
