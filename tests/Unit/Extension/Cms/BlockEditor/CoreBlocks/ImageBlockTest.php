<?php

declare(strict_types=1);

namespace Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

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
    public function rendersImageWithAlt(): void
    {
        $html = $this->block->render(['src' => '/photo.jpg', 'alt' => 'A photo']);

        self::assertStringContainsString('<figure>', $html);
        self::assertStringContainsString('<img src="/photo.jpg" alt="A photo">', $html);
        self::assertStringContainsString('</figure>', $html);
    }

    #[Test]
    public function rendersImageWithCaption(): void
    {
        $html = $this->block->render([
            'src' => '/photo.jpg',
            'alt' => 'A photo',
            'caption' => 'Photo caption',
        ]);

        self::assertStringContainsString('<figcaption>Photo caption</figcaption>', $html);
    }

    #[Test]
    public function rendersImageWithAlignment(): void
    {
        $html = $this->block->render([
            'src' => '/photo.jpg',
            'alt' => 'A photo',
            'alignment' => 'center',
        ]);

        self::assertStringContainsString('style="text-align:center"', $html);
    }

    #[Test]
    public function omitsFigcaptionWhenNoCaptionProvided(): void
    {
        $html = $this->block->render(['src' => '/photo.jpg', 'alt' => 'A photo']);

        self::assertStringNotContainsString('<figcaption', $html);
    }

    #[Test]
    public function escapesXssInSrc(): void
    {
        $html = $this->block->render([
            'src' => '" onload="alert(1)',
            'alt' => 'test',
        ]);

        // The double-quote is escaped to &quot;, preventing attribute injection
        self::assertStringContainsString('src="&quot;', $html);
    }

    #[Test]
    public function escapesXssInAlt(): void
    {
        $html = $this->block->render([
            'src' => '/img.jpg',
            'alt' => '<script>xss</script>',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function validatesRequiredSrc(): void
    {
        $errors = $this->block->validate(['alt' => 'desc']);

        self::assertContains('src is required and must be a string', $errors);
    }

    #[Test]
    public function validatesRequiredAlt(): void
    {
        $errors = $this->block->validate(['src' => '/img.jpg']);

        self::assertContains('alt is required and must be a string', $errors);
    }

    #[Test]
    public function validatesInvalidAlignment(): void
    {
        $errors = $this->block->validate([
            'src' => '/img.jpg',
            'alt' => 'test',
            'alignment' => 'stretch',
        ]);

        self::assertContains('alignment must be one of: left, center, right', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate([
            'src' => '/img.jpg',
            'alt' => 'An image',
            'alignment' => 'left',
        ]);

        self::assertSame([], $errors);
    }
}
