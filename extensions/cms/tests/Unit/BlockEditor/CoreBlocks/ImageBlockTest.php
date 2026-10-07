<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\ImageBlock;

#[CoversClass(ImageBlock::class)]
final class ImageBlockTest extends TestCase
{
    private ImageBlock $block;

    protected function setUp(): void
    {
        $this->block = new ImageBlock();
    }

    #[Test]
    public function typeReturnsImage(): void
    {
        self::assertSame('image', $this->block->type());
    }

    #[Test]
    public function renderOutputsFigureWithImg(): void
    {
        $html = $this->block->render([
            'src' => '/img/photo.jpg',
            'alt' => 'A photo',
        ]);

        self::assertStringContainsString('<figure', $html);
        self::assertStringContainsString('src="/img/photo.jpg"', $html);
        self::assertStringContainsString('alt="A photo"', $html);
        self::assertStringContainsString('loading="lazy"', $html);
    }

    #[Test]
    public function renderIncludesStructuredData(): void
    {
        $html = $this->block->render([
            'src' => '/img/photo.jpg',
            'alt' => 'Photo',
        ]);

        self::assertStringContainsString('application/ld+json', $html);
        self::assertStringContainsString('ImageObject', $html);
    }

    #[Test]
    public function renderIncludesCaptionInFigure(): void
    {
        $html = $this->block->render([
            'src' => '/img/photo.jpg',
            'alt' => 'Photo',
            'caption' => 'Beautiful sunset',
        ]);

        self::assertStringContainsString('<figcaption>Beautiful sunset</figcaption>', $html);
    }

    #[Test]
    public function renderAppliesAlignment(): void
    {
        $html = $this->block->render([
            'src' => '/img/photo.jpg',
            'alt' => 'Photo',
            'alignment' => 'center',
        ]);

        self::assertStringContainsString('text-align:center', $html);
    }

    #[Test]
    public function validateReturnsErrorForMissingSrc(): void
    {
        $errors = $this->block->validate(['alt' => 'Photo']);

        self::assertStringContainsString('src is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForMissingAlt(): void
    {
        $errors = $this->block->validate(['src' => '/photo.jpg']);

        self::assertStringContainsString('alt is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForInvalidAlignment(): void
    {
        $errors = $this->block->validate([
            'src' => '/photo.jpg',
            'alt' => 'Photo',
            'alignment' => 'stretch',
        ]);

        self::assertNotEmpty($errors);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        self::assertSame([], $this->block->validate([
            'src' => '/photo.jpg',
            'alt' => 'Photo',
        ]));
    }
}
