<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Accessibility\Contrast;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Contrast\LuminanceCalculator;
use Pulsar\Extension\Accessibility\Contrast\ParsedColor;

#[CoversClass(LuminanceCalculator::class)]
final class LuminanceCalculatorDeepTest extends TestCase
{
    private LuminanceCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new LuminanceCalculator();
    }

    #[Test]
    public function redLuminance(): void
    {
        // Pure red has a relative luminance of ~0.2126
        $red = new ParsedColor(255, 0, 0);
        self::assertEqualsWithDelta(0.2126, $this->calc->relativeLuminance($red), 0.001);
    }

    #[Test]
    public function greenLuminance(): void
    {
        // Pure green (0,255,0) has ~0.7152
        $green = new ParsedColor(0, 255, 0);
        self::assertEqualsWithDelta(0.7152, $this->calc->relativeLuminance($green), 0.001);
    }

    #[Test]
    public function blueLuminance(): void
    {
        // Pure blue has ~0.0722
        $blue = new ParsedColor(0, 0, 255);
        self::assertEqualsWithDelta(0.0722, $this->calc->relativeLuminance($blue), 0.001);
    }

    #[Test]
    public function lowValueChannelUsesLinearPath(): void
    {
        // Channel values <= 0.04045 * 255 ≈ 10.3 use the linear sRGB path
        $dark = new ParsedColor(10, 10, 10);
        $lum = $this->calc->relativeLuminance($dark);

        self::assertGreaterThan(0.0, $lum);
        self::assertLessThan(0.01, $lum);
    }

    #[Test]
    public function contrastRatioFgLighterThanBg(): void
    {
        $white = new ParsedColor(255, 255, 255);
        $black = new ParsedColor(0, 0, 0);

        // When fg is lighter, result should still be correct
        $ratio = $this->calc->contrastRatio($white, $black);
        self::assertEqualsWithDelta(21.0, $ratio, 0.1);
    }

    #[Test]
    public function contrastRatioMidGray(): void
    {
        $mid = new ParsedColor(128, 128, 128);
        $white = new ParsedColor(255, 255, 255);

        $ratio = $this->calc->contrastRatio($mid, $white);
        // Mid gray vs white should be around 3.9
        self::assertGreaterThan(3.0, $ratio);
        self::assertLessThan(5.0, $ratio);
    }
}
