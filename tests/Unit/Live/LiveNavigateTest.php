<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\LiveNavigate;

#[CoversClass(LiveNavigate::class)]
final class LiveNavigateTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $nav = new LiveNavigate();

        self::assertTrue($nav->enabled);
        self::assertTrue($nav->prefetch);
        self::assertTrue($nav->progressBar);
        self::assertSame('#4f46e5', $nav->progressColor);
        self::assertSame(2, $nav->progressHeight);
    }

    #[Test]
    public function attributeReturnsWireNavigate(): void
    {
        $nav = new LiveNavigate();

        self::assertSame('wire:navigate', $nav->attribute());
    }

    #[Test]
    public function attributeWithPrefetch(): void
    {
        $nav = new LiveNavigate();

        self::assertSame('wire:navigate.prefetch', $nav->attribute(prefetch: true));
    }

    #[Test]
    public function attributeReturnsEmptyWhenDisabled(): void
    {
        $nav = new LiveNavigate(enabled: false);

        self::assertSame('', $nav->attribute());
    }

    #[Test]
    public function progressBarStylesContainsCss(): void
    {
        $nav = new LiveNavigate(progressColor: '#ff0000', progressHeight: 3);
        $css = $nav->progressBarStyles();

        self::assertStringContainsString('[data-live-progress]', $css);
        self::assertStringContainsString('#ff0000', $css);
        self::assertStringContainsString('3px', $css);
    }

    #[Test]
    public function progressBarStylesReturnsEmptyWhenDisabled(): void
    {
        $nav = new LiveNavigate(progressBar: false);

        self::assertSame('', $nav->progressBarStyles());
    }

    #[Test]
    public function progressBarEscapesColorValue(): void
    {
        $nav = new LiveNavigate(progressColor: '<script>');
        $css = $nav->progressBarStyles();

        self::assertStringNotContainsString('<script>', $css);
    }
}
