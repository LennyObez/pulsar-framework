<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\VideoBlock;

#[CoversClass(VideoBlock::class)]
final class VideoBlockTest extends TestCase
{
    private VideoBlock $block;

    protected function setUp(): void
    {
        $this->block = new VideoBlock();
    }

    #[Test]
    public function typeReturnsVideo(): void
    {
        self::assertSame('video', $this->block->type());
    }

    #[Test]
    public function renderOutputsVideoElement(): void
    {
        $html = $this->block->render(['src' => '/media/intro.mp4']);

        self::assertStringContainsString('<video controls src="/media/intro.mp4">', $html);
        self::assertStringContainsString('video-block', $html);
    }

    #[Test]
    public function renderIncludesPosterAttribute(): void
    {
        $html = $this->block->render([
            'src' => '/media/intro.mp4',
            'poster' => '/media/poster.jpg',
        ]);

        self::assertStringContainsString('poster="/media/poster.jpg"', $html);
    }

    #[Test]
    public function renderIncludesCaption(): void
    {
        $html = $this->block->render([
            'src' => '/media/intro.mp4',
            'caption' => 'Product demo',
        ]);

        self::assertStringContainsString('<figcaption>Product demo</figcaption>', $html);
    }

    #[Test]
    public function renderIncludesVideoStructuredData(): void
    {
        $html = $this->block->render([
            'src' => '/media/intro.mp4',
            'caption' => 'Demo',
        ]);

        self::assertStringContainsString('application/ld+json', $html);
        self::assertStringContainsString('VideoObject', $html);
    }

    #[Test]
    public function renderOmitsStructuredDataForEmptySrc(): void
    {
        $html = $this->block->render(['src' => '']);

        self::assertStringNotContainsString('ld+json', $html);
    }

    #[Test]
    public function renderSupportsAnchorAndClassName(): void
    {
        $html = $this->block->render([
            'src' => '/v.mp4',
            'anchor' => 'demo',
            'className' => 'featured',
        ]);

        self::assertStringContainsString('id="demo"', $html);
        self::assertStringContainsString('featured', $html);
    }

    #[Test]
    public function validateReturnsErrorForMissingSrc(): void
    {
        $errors = $this->block->validate([]);

        self::assertStringContainsString('src is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        self::assertSame([], $this->block->validate(['src' => '/video.mp4']));
    }
}
