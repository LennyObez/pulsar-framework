<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\GalleryBlock;

#[CoversClass(GalleryBlock::class)]
final class GalleryBlockTest extends TestCase
{
    private GalleryBlock $block;

    protected function setUp(): void
    {
        $this->block = new GalleryBlock();
    }

    #[Test]
    public function typeReturnsGallery(): void
    {
        self::assertSame('gallery', $this->block->type());
    }

    #[Test]
    public function renderOutputsGridWithImages(): void
    {
        $html = $this->block->render([
            'images' => [
                ['src' => '/img/a.jpg', 'alt' => 'Image A'],
                ['src' => '/img/b.jpg', 'alt' => 'Image B'],
            ],
        ]);

        self::assertStringContainsString('data-gallery', $html);
        self::assertStringContainsString('src="/img/a.jpg"', $html);
        self::assertStringContainsString('alt="Image B"', $html);
        self::assertStringContainsString('loading="lazy"', $html);
    }

    #[Test]
    public function renderUsesDefaultThreeColumns(): void
    {
        $html = $this->block->render([
            'images' => [['src' => '/a.jpg', 'alt' => 'A']],
        ]);

        self::assertStringContainsString('repeat(3,1fr)', $html);
    }

    #[Test]
    public function renderUsesCustomColumnCount(): void
    {
        $html = $this->block->render([
            'images' => [['src' => '/a.jpg', 'alt' => 'A']],
            'columns' => 4,
        ]);

        self::assertStringContainsString('repeat(4,1fr)', $html);
    }

    #[Test]
    public function renderClampsNegativeColumnsToDefault(): void
    {
        $html = $this->block->render([
            'images' => [['src' => '/a.jpg', 'alt' => 'A']],
            'columns' => -1,
        ]);

        self::assertStringContainsString('repeat(3,1fr)', $html);
    }

    #[Test]
    public function renderIncludesCaptionInFigcaption(): void
    {
        $html = $this->block->render([
            'images' => [
                ['src' => '/a.jpg', 'alt' => 'A', 'caption' => 'Sunset view'],
            ],
        ]);

        self::assertStringContainsString('<figcaption>Sunset view</figcaption>', $html);
    }

    #[Test]
    public function validateReturnsErrorForMissingImages(): void
    {
        $errors = $this->block->validate([]);

        self::assertStringContainsString('images is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForMissingImageFields(): void
    {
        $errors = $this->block->validate([
            'images' => [['src' => 42]],
        ]);

        self::assertNotEmpty($errors);
    }

    #[Test]
    public function validateReturnsErrorForNegativeColumns(): void
    {
        $errors = $this->block->validate([
            'images' => [['src' => '/a.jpg', 'alt' => 'A']],
            'columns' => -1,
        ]);

        self::assertNotEmpty($errors);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        self::assertSame([], $this->block->validate([
            'images' => [['src' => '/a.jpg', 'alt' => 'Photo']],
            'columns' => 3,
        ]));
    }
}
