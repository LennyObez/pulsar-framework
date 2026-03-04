<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\AudioDirective;

#[CoversClass(AudioDirective::class)]
final class AudioDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsAudio(): void
    {
        $directive = new AudioDirective();

        self::assertSame('audio', $directive->name());
    }

    #[Test]
    public function compileProducesAudioPlayerMarkup(): void
    {
        $directive = new AudioDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('pui-audio-player', $output);
        self::assertStringContainsString('data-pui-audio', $output);
        self::assertStringContainsString('<audio', $output);
        self::assertStringContainsString('</audio>', $output);
    }

    #[Test]
    public function compileIncludesWaveformCanvas(): void
    {
        $directive = new AudioDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('pui-audio-player__waveform', $output);
        self::assertStringContainsString('<canvas', $output);
        self::assertStringContainsString('pui-audio-player__progress-overlay', $output);
    }

    #[Test]
    public function compileIncludesControlButtons(): void
    {
        $directive = new AudioDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('data-action="play"', $output);
        self::assertStringContainsString('data-action="mute"', $output);
        self::assertStringContainsString('data-action="speed"', $output);
    }

    #[Test]
    public function compileIncludesTrackInfo(): void
    {
        $directive = new AudioDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('pui-audio-player__title', $output);
        self::assertStringContainsString('pui-audio-player__artist', $output);
    }

    #[Test]
    public function compileIncludesVolumeControl(): void
    {
        $directive = new AudioDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('pui-audio-player__volume', $output);
        self::assertStringContainsString('aria-label="Volume"', $output);
    }

    #[Test]
    public function compileIncludesTimeDisplay(): void
    {
        $directive = new AudioDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('data-time="current"', $output);
        self::assertStringContainsString('data-time="duration"', $output);
    }

    #[Test]
    public function compileEscapesOutputWithHtmlspecialchars(): void
    {
        $directive = new AudioDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('htmlspecialchars', $output);
        self::assertStringContainsString('ENT_QUOTES', $output);
    }

    #[Test]
    public function compileIncludesAccessibilityAttributes(): void
    {
        $directive = new AudioDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('role="region"', $output);
        self::assertStringContainsString('role="toolbar"', $output);
        self::assertStringContainsString('aria-label="Seek"', $output);
    }

    #[Test]
    public function compileUnsetsTemporaryVariables(): void
    {
        $directive = new AudioDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('unset(', $output);
        self::assertStringContainsString('$__audioArgs', $output);
        self::assertStringContainsString('$__audioId', $output);
    }

    #[Test]
    public function compileIncludesWaveformDataAttribute(): void
    {
        $directive = new AudioDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('data-waveform', $output);
    }
}
