<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\AudioBlock;

#[CoversClass(AudioBlock::class)]
final class AudioBlockTest extends TestCase
{
    private AudioBlock $block;

    protected function setUp(): void
    {
        $this->block = new AudioBlock();
    }

    #[Test]
    public function typeReturnsAudio(): void
    {
        self::assertSame('audio', $this->block->type());
    }

    #[Test]
    public function rendersAudioWithControls(): void
    {
        $html = $this->block->render(['src' => '/audio/track.mp3']);

        self::assertStringContainsString('<figure class="audio-block">', $html);
        self::assertStringContainsString('<audio controls src="/audio/track.mp3"></audio>', $html);
        self::assertStringContainsString('</figure>', $html);
    }

    #[Test]
    public function rendersAudioWithCaption(): void
    {
        $html = $this->block->render([
            'src' => '/audio/track.mp3',
            'caption' => 'My favorite song',
        ]);

        self::assertStringContainsString('<figcaption>My favorite song</figcaption>', $html);
    }

    #[Test]
    public function omitsFigcaptionWhenNoCaptionProvided(): void
    {
        $html = $this->block->render(['src' => '/audio/track.mp3']);

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
    public function escapesXssInCaption(): void
    {
        $html = $this->block->render([
            'src' => '/audio/track.mp3',
            'caption' => '<script>xss</script>',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function validatesRequiredSrc(): void
    {
        $errors = $this->block->validate([]);

        self::assertContains('src is required and must be a string', $errors);
    }

    #[Test]
    public function validatesSrcMustBeString(): void
    {
        $errors = $this->block->validate(['src' => 123]);

        self::assertContains('src is required and must be a string', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate(['src' => '/audio/track.mp3']);

        self::assertSame([], $errors);
    }
}
