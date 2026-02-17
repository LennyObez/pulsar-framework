<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\VideoDirective;

#[CoversClass(VideoDirective::class)]
final class VideoDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsVideo(): void
    {
        $directive = new VideoDirective();

        self::assertSame('video', $directive->name());
    }

    #[Test]
    public function compileProducesVideoPlayerMarkup(): void
    {
        $directive = new VideoDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('pui-video-player', $output);
        self::assertStringContainsString('data-pui-video', $output);
        self::assertStringContainsString('<video', $output);
        self::assertStringContainsString('</video>', $output);
    }

    #[Test]
    public function compileIncludesControlButtons(): void
    {
        $directive = new VideoDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('data-action="play"', $output);
        self::assertStringContainsString('data-action="mute"', $output);
        self::assertStringContainsString('data-action="fullscreen"', $output);
        self::assertStringContainsString('data-action="speed"', $output);
        self::assertStringContainsString('data-action="quality"', $output);
    }

    #[Test]
    public function compileIncludesSeekAndVolumeControls(): void
    {
        $directive = new VideoDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('pui-video-player__seek', $output);
        self::assertStringContainsString('pui-video-player__volume', $output);
        self::assertStringContainsString('aria-label="Seek"', $output);
        self::assertStringContainsString('aria-label="Volume"', $output);
    }

    #[Test]
    public function compileIncludesTimeDisplay(): void
    {
        $directive = new VideoDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('data-time="current"', $output);
        self::assertStringContainsString('data-time="duration"', $output);
    }

    #[Test]
    public function compileIncludesHlsDataAttribute(): void
    {
        $directive = new VideoDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('data-hls-src', $output);
    }

    #[Test]
    public function compileEscapesOutputWithHtmlspecialchars(): void
    {
        $directive = new VideoDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('htmlspecialchars', $output);
        self::assertStringContainsString('ENT_QUOTES', $output);
    }

    #[Test]
    public function compileIncludesAccessibilityAttributes(): void
    {
        $directive = new VideoDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('role="region"', $output);
        self::assertStringContainsString('aria-label=', $output);
        self::assertStringContainsString('role="toolbar"', $output);
        self::assertStringContainsString('playsinline', $output);
    }

    #[Test]
    public function compileUnsetsTemporaryVariables(): void
    {
        $directive = new VideoDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('unset(', $output);
        self::assertStringContainsString('$__videoArgs', $output);
        self::assertStringContainsString('$__videoId', $output);
    }

    #[Test]
    public function compileIncludesSpeedOptions(): void
    {
        $directive = new VideoDirective();

        $output = $directive->compile('$mediaId');

        self::assertStringContainsString('0.5x', $output);
        self::assertStringContainsString('1x', $output);
        self::assertStringContainsString('2x', $output);
    }
}
