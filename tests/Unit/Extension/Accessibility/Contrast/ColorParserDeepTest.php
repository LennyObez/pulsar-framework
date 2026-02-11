<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Accessibility\Contrast;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Contrast\ColorParser;
use Pulsar\Extension\Accessibility\Contrast\InvalidColorException;

#[CoversClass(ColorParser::class)]
final class ColorParserDeepTest extends TestCase
{
    private ColorParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ColorParser();
    }

    // --- Hex #RGBA (4-char shorthand with alpha) ---

    #[Test]
    public function hexRgbaShorthand(): void
    {
        $color = $this->parser->parse('#f00f');

        self::assertSame(255, $color->r);
        self::assertSame(0, $color->g);
        self::assertSame(0, $color->b);
        self::assertSame(1.0, $color->alpha);
    }

    #[Test]
    public function hexRgbaShorthandHalfAlpha(): void
    {
        // #8 = 0x88 = 136, 136/255 = 0.53
        $color = $this->parser->parse('#ff08');

        self::assertSame(255, $color->r);
        self::assertSame(255, $color->g);
        self::assertSame(0, $color->b);
        self::assertEqualsWithDelta(0.53, $color->alpha, 0.02);
    }

    // --- Hex #RRGGBBAA ---

    #[Test]
    public function hexRrggbbaaFullAlpha(): void
    {
        $color = $this->parser->parse('#00FF00FF');

        self::assertSame(0, $color->r);
        self::assertSame(255, $color->g);
        self::assertSame(0, $color->b);
        self::assertSame(1.0, $color->alpha);
    }

    #[Test]
    public function hexRrggbbaaZeroAlpha(): void
    {
        $color = $this->parser->parse('#0000FF00');

        self::assertSame(0, $color->r);
        self::assertSame(0, $color->g);
        self::assertSame(255, $color->b);
        self::assertSame(0.0, $color->alpha);
    }

    // --- Invalid hex lengths ---

    #[Test]
    public function hexInvalidLengthTwoThrows(): void
    {
        $this->expectException(InvalidColorException::class);
        $this->parser->parse('#FF');
    }

    #[Test]
    public function hexInvalidLengthSevenThrows(): void
    {
        $this->expectException(InvalidColorException::class);
        $this->parser->parse('#AABBCC1');
    }

    // --- rgb() edge cases ---

    #[Test]
    public function rgbClampsAbove255(): void
    {
        $color = $this->parser->parse('rgb(300, 256, 999)');

        self::assertSame(255, $color->r);
        self::assertSame(255, $color->g);
        self::assertSame(255, $color->b);
    }

    #[Test]
    public function rgbClampsNegativeToZero(): void
    {
        $color = $this->parser->parse('rgb(-10, -1, -255)');

        self::assertSame(0, $color->r);
        self::assertSame(0, $color->g);
        self::assertSame(0, $color->b);
    }

    #[Test]
    public function rgbWithFloatValues(): void
    {
        $color = $this->parser->parse('rgb(127.6, 63.4, 200.5)');

        self::assertSame(128, $color->r);
        self::assertSame(63, $color->g);
        self::assertSame(201, $color->b);
    }

    #[Test]
    public function rgbTooFewArgsThrows(): void
    {
        $this->expectException(InvalidColorException::class);
        $this->parser->parse('rgb(255, 128)');
    }

    #[Test]
    public function rgbTooManyArgsThrows(): void
    {
        $this->expectException(InvalidColorException::class);
        $this->parser->parse('rgb(1, 2, 3, 4, 5)');
    }

    #[Test]
    public function rgbNoParensThrows(): void
    {
        $this->expectException(InvalidColorException::class);
        $this->parser->parse('rgb 255 0 0');
    }

    // --- rgba() ---

    #[Test]
    public function rgbaWithPercentAlpha(): void
    {
        $color = $this->parser->parse('rgba(100, 200, 50, 50%)');

        self::assertSame(100, $color->r);
        self::assertSame(200, $color->g);
        self::assertSame(50, $color->b);
        self::assertSame(0.5, $color->alpha);
    }

    #[Test]
    public function rgbaAlphaClampedAboveOne(): void
    {
        $color = $this->parser->parse('rgba(0, 0, 0, 1.5)');

        self::assertSame(1.0, $color->alpha);
    }

    #[Test]
    public function rgbaAlphaClampedBelowZero(): void
    {
        $color = $this->parser->parse('rgba(0, 0, 0, -0.5)');

        self::assertSame(0.0, $color->alpha);
    }

    // --- rgb() space-separated with slash alpha ---

    #[Test]
    public function rgbSpaceSlashAlpha(): void
    {
        $color = $this->parser->parse('rgb(255 128 64 / 0.75)');

        self::assertSame(255, $color->r);
        self::assertSame(128, $color->g);
        self::assertSame(64, $color->b);
        self::assertSame(0.75, $color->alpha);
    }

    #[Test]
    public function rgbSpaceSlashPercentAlpha(): void
    {
        $color = $this->parser->parse('rgb(0 0 0 / 25%)');

        self::assertSame(0, $color->r);
        self::assertSame(0, $color->g);
        self::assertSame(0, $color->b);
        self::assertSame(0.25, $color->alpha);
    }

    // --- hsl() edge cases ---

    #[Test]
    public function hslGreenAt120Degrees(): void
    {
        $color = $this->parser->parse('hsl(120, 100%, 50%)');

        self::assertSame(0, $color->r);
        self::assertSame(255, $color->g);
        self::assertSame(0, $color->b);
    }

    #[Test]
    public function hslBlueAt240Degrees(): void
    {
        $color = $this->parser->parse('hsl(240, 100%, 50%)');

        self::assertSame(0, $color->r);
        self::assertSame(0, $color->g);
        self::assertSame(255, $color->b);
    }

    #[Test]
    public function hslAchromatic(): void
    {
        // s=0 means grayscale, l=50% => 128
        $color = $this->parser->parse('hsl(0, 0%, 50%)');

        self::assertSame(128, $color->r);
        self::assertSame(128, $color->g);
        self::assertSame(128, $color->b);
    }

    #[Test]
    public function hslHighLightness(): void
    {
        // l >= 0.5 takes the other branch of q calculation
        $color = $this->parser->parse('hsl(60, 50%, 80%)');

        // Verify it produces valid output (not zero)
        self::assertGreaterThan(0, $color->r);
        self::assertGreaterThan(0, $color->g);
        self::assertGreaterThan(0, $color->b);
    }

    #[Test]
    public function hslNegativeHueWraps(): void
    {
        // -60 degrees should equal 300 degrees (magenta-ish)
        $color = $this->parser->parse('hsl(-60, 100%, 50%)');

        // 300 degrees = magenta
        self::assertSame(255, $color->r);
        self::assertSame(0, $color->g);
        self::assertSame(255, $color->b);
    }

    #[Test]
    public function hslHueAbove360Wraps(): void
    {
        // 420 degrees = 60 degrees (yellow)
        $color = $this->parser->parse('hsl(420, 100%, 50%)');

        self::assertSame(255, $color->r);
        self::assertSame(255, $color->g);
        self::assertSame(0, $color->b);
    }

    // --- hsla() ---

    #[Test]
    public function hslaWithAlpha(): void
    {
        $color = $this->parser->parse('hsla(0, 100%, 50%, 0.5)');

        self::assertSame(255, $color->r);
        self::assertSame(0, $color->g);
        self::assertSame(0, $color->b);
        self::assertSame(0.5, $color->alpha);
    }

    #[Test]
    public function hslTooFewArgsThrows(): void
    {
        $this->expectException(InvalidColorException::class);
        $this->parser->parse('hsl(120, 50%)');
    }

    #[Test]
    public function hslTooManyArgsThrows(): void
    {
        $this->expectException(InvalidColorException::class);
        $this->parser->parse('hsl(120, 50%, 50%, 0.5, extra)');
    }

    #[Test]
    public function hslNoParensThrows(): void
    {
        $this->expectException(InvalidColorException::class);
        $this->parser->parse('hsl 120 50% 50%');
    }

    // --- hsl space-separated with slash alpha ---

    #[Test]
    public function hslSpaceSlashAlpha(): void
    {
        $color = $this->parser->parse('hsl(0 100% 50% / 0.3)');

        self::assertSame(255, $color->r);
        self::assertSame(0, $color->g);
        self::assertSame(0, $color->b);
        self::assertEqualsWithDelta(0.3, $color->alpha, 0.01);
    }

    // --- Named colors ---

    #[Test]
    public function namedColorTransparent(): void
    {
        $color = $this->parser->parse('transparent');

        self::assertSame(0, $color->r);
        self::assertSame(0, $color->g);
        self::assertSame(0, $color->b);
        self::assertSame(0.0, $color->alpha);
    }

    #[Test]
    public function namedColorOrange(): void
    {
        $color = $this->parser->parse('orange');

        self::assertSame(255, $color->r);
        self::assertSame(165, $color->g);
        self::assertSame(0, $color->b);
    }

    #[Test]
    public function namedColorCaseInsensitiveMixed(): void
    {
        $color = $this->parser->parse('DarkBlue');

        self::assertSame(0, $color->r);
        self::assertSame(0, $color->g);
        self::assertSame(139, $color->b);
    }

    // --- Whitespace handling ---

    #[Test]
    public function trimsWhitespace(): void
    {
        $color = $this->parser->parse('  #FF0000  ');

        self::assertSame(255, $color->r);
        self::assertSame(0, $color->g);
        self::assertSame(0, $color->b);
    }

    #[Test]
    public function whitespaceOnlyThrows(): void
    {
        $this->expectException(InvalidColorException::class);
        $this->parser->parse('   ');
    }

    // --- ParsedColor::toHex() ---

    #[Test]
    public function parsedColorToHex(): void
    {
        $color = $this->parser->parse('#ff8800');

        self::assertSame('#ff8800', $color->toHex());
    }

    #[Test]
    public function parsedColorToHexFromRgb(): void
    {
        $color = $this->parser->parse('rgb(0, 128, 255)');

        self::assertSame('#0080ff', $color->toHex());
    }
}
