<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Contrast;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Contrast\ContrastResult;
use Pulsar\Extension\Accessibility\Contrast\ParsedColor;

final class ContrastResultTest extends TestCase
{
    #[Test]
    public function highContrastPassesAllLevels(): void
    {
        $fg = new ParsedColor(0, 0, 0);
        $bg = new ParsedColor(255, 255, 255);

        $result = new ContrastResult($fg, $bg, '--text', '--bg', 21.0);

        self::assertTrue($result->passesAaNormal);
        self::assertTrue($result->passesAaLarge);
        self::assertTrue($result->passesAaaNormal);
        self::assertTrue($result->passesAaaLarge);
    }

    #[Test]
    public function lowContrastFailsAllLevels(): void
    {
        $fg = new ParsedColor(200, 200, 200);
        $bg = new ParsedColor(210, 210, 210);

        $result = new ContrastResult($fg, $bg, '--light-text', '--light-bg', 1.1);

        self::assertFalse($result->passesAaNormal);
        self::assertFalse($result->passesAaLarge);
        self::assertFalse($result->passesAaaNormal);
        self::assertFalse($result->passesAaaLarge);
    }

    #[Test]
    public function ratio45PassesAaNormalAndAaaLargeButNotAaaNormal(): void
    {
        $fg = new ParsedColor(100, 100, 100);
        $bg = new ParsedColor(255, 255, 255);

        $result = new ContrastResult($fg, $bg, '--mid', '--white', 4.5);

        self::assertTrue($result->passesAaNormal);
        self::assertTrue($result->passesAaLarge);
        self::assertFalse($result->passesAaaNormal);
        self::assertTrue($result->passesAaaLarge);
    }

    #[Test]
    public function ratio3PassesOnlyAaLarge(): void
    {
        $fg = new ParsedColor(150, 150, 150);
        $bg = new ParsedColor(255, 255, 255);

        $result = new ContrastResult($fg, $bg, '--grey', '--white', 3.0);

        self::assertFalse($result->passesAaNormal);
        self::assertTrue($result->passesAaLarge);
        self::assertFalse($result->passesAaaNormal);
        self::assertFalse($result->passesAaaLarge);
    }

    #[Test]
    public function ratio7PassesAaaNormal(): void
    {
        $fg = new ParsedColor(0, 0, 0);
        $bg = new ParsedColor(200, 200, 200);

        $result = new ContrastResult($fg, $bg, '--dark', '--silver', 7.0);

        self::assertTrue($result->passesAaaNormal);
    }

    #[Test]
    public function storesTokenNames(): void
    {
        $fg = new ParsedColor(0, 0, 0);
        $bg = new ParsedColor(255, 255, 255);

        $result = new ContrastResult($fg, $bg, '--color-text', '--color-bg', 21.0);

        self::assertSame('--color-text', $result->foregroundToken);
        self::assertSame('--color-bg', $result->backgroundToken);
    }

    #[Test]
    public function storesRatio(): void
    {
        $fg = new ParsedColor(0, 0, 0);
        $bg = new ParsedColor(255, 255, 255);

        $result = new ContrastResult($fg, $bg, '--a', '--b', 15.3);

        self::assertSame(15.3, $result->ratio);
    }

    #[Test]
    public function storesColorObjects(): void
    {
        $fg = new ParsedColor(10, 20, 30);
        $bg = new ParsedColor(240, 250, 255);

        $result = new ContrastResult($fg, $bg, '--fg', '--bg', 18.0);

        self::assertSame(10, $result->foreground->r);
        self::assertSame(240, $result->background->r);
    }
}
