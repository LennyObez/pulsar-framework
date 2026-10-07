<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

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
    public function schemaRequiresSrc(): void
    {
        self::assertSame(['src'], $this->block->schema()['required']);
    }

    #[Test]
    public function renderOutputsAudioElement(): void
    {
        $html = $this->block->render([
            'src' => '/media/song.mp3',
        ]);

        self::assertStringContainsString('<audio controls src="/media/song.mp3">', $html);
        self::assertStringContainsString('audio-block', $html);
    }

    #[Test]
    public function renderIncludesCaptionWhenProvided(): void
    {
        $html = $this->block->render([
            'src' => '/media/song.mp3',
            'caption' => 'My favorite song',
        ]);

        self::assertStringContainsString('<figcaption>My favorite song</figcaption>', $html);
    }

    #[Test]
    public function renderOmitsCaptionWhenEmpty(): void
    {
        $html = $this->block->render([
            'src' => '/media/song.mp3',
            'caption' => '',
        ]);

        self::assertStringNotContainsString('figcaption', $html);
    }

    #[Test]
    public function renderEscapesSrcAttribute(): void
    {
        $html = $this->block->render([
            'src' => '/media/file.mp3?a=1&b=2',
        ]);

        self::assertStringContainsString('a=1&amp;b=2', $html);
    }

    #[Test]
    public function validateReturnsErrorWhenSrcMissing(): void
    {
        $errors = $this->block->validate([]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('src is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        self::assertSame([], $this->block->validate(['src' => '/audio.mp3']));
    }
}
