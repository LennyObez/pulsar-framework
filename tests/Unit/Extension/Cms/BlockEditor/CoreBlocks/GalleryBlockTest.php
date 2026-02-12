<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

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
    public function rendersGalleryGrid(): void
    {
        $html = $this->block->render([
            'images' => [
                ['src' => '/a.jpg', 'alt' => 'Image A'],
                ['src' => '/b.jpg', 'alt' => 'Image B'],
            ],
            'columns' => 2,
        ]);

        self::assertStringContainsString('class="gallery"', $html);
        self::assertStringContainsString('grid-template-columns:repeat(2,1fr)', $html);
        self::assertStringContainsString('<img src="/a.jpg" alt="Image A">', $html);
        self::assertStringContainsString('<img src="/b.jpg" alt="Image B">', $html);
    }

    #[Test]
    public function rendersGalleryWithCaption(): void
    {
        $html = $this->block->render([
            'images' => [
                ['src' => '/a.jpg', 'alt' => 'A', 'caption' => 'Caption A'],
            ],
        ]);

        self::assertStringContainsString('<figcaption>Caption A</figcaption>', $html);
    }

    #[Test]
    public function defaultsToThreeColumns(): void
    {
        $html = $this->block->render([
            'images' => [['src' => '/a.jpg', 'alt' => 'A']],
        ]);

        self::assertStringContainsString('repeat(3,1fr)', $html);
    }

    #[Test]
    public function validatesRequiredImages(): void
    {
        $errors = $this->block->validate([]);

        self::assertContains('images is required and must be an array', $errors);
    }

    #[Test]
    public function validatesImagesSrcRequired(): void
    {
        $errors = $this->block->validate([
            'images' => [['alt' => 'No src']],
        ]);

        self::assertContains('images[0].src is required and must be a string', $errors);
    }

    #[Test]
    public function validatesColumnsPositiveInteger(): void
    {
        $errors = $this->block->validate([
            'images' => [['src' => '/a.jpg', 'alt' => 'A']],
            'columns' => 0,
        ]);

        self::assertContains('columns must be a positive integer', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate([
            'images' => [['src' => '/a.jpg', 'alt' => 'A']],
            'columns' => 4,
        ]);

        self::assertSame([], $errors);
    }
}
