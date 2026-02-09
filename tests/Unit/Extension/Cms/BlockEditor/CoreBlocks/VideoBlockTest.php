<?php

declare(strict_types=1);

namespace Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

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
    public function rendersVideoWithControls(): void
    {
        $html = $this->block->render(['src' => '/video/clip.mp4']);

        self::assertStringContainsString('<figure class="video-block">', $html);
        self::assertStringContainsString('<video controls src="/video/clip.mp4"></video>', $html);
        self::assertStringContainsString('</figure>', $html);
    }

    #[Test]
    public function rendersVideoWithPoster(): void
    {
        $html = $this->block->render([
            'src' => '/video/clip.mp4',
            'poster' => '/img/poster.jpg',
        ]);

        self::assertStringContainsString('poster="/img/poster.jpg"', $html);
    }

    #[Test]
    public function rendersVideoWithoutPoster(): void
    {
        $html = $this->block->render(['src' => '/video/clip.mp4']);

        self::assertStringNotContainsString('poster=', $html);
    }

    #[Test]
    public function rendersVideoWithCaption(): void
    {
        $html = $this->block->render([
            'src' => '/video/clip.mp4',
            'caption' => 'A great video',
        ]);

        self::assertStringContainsString('<figcaption>A great video</figcaption>', $html);
    }

    #[Test]
    public function omitsFigcaptionWhenNoCaptionProvided(): void
    {
        $html = $this->block->render(['src' => '/video/clip.mp4']);

        self::assertStringNotContainsString('<figcaption', $html);
    }

    #[Test]
    public function escapesXssInSrc(): void
    {
        $html = $this->block->render([
            'src' => '<script>alert("xss")</script>',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function escapesXssInPoster(): void
    {
        $html = $this->block->render([
            'src' => '/video/clip.mp4',
            'poster' => '" onload="alert(1)',
        ]);

        self::assertStringContainsString('poster="&quot;', $html);
    }

    #[Test]
    public function validatesRequiredSrc(): void
    {
        $errors = $this->block->validate([]);

        self::assertContains('src is required and must be a string', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate(['src' => '/video/clip.mp4']);

        self::assertSame([], $errors);
    }
}
