<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Contrast;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Contrast\LuminanceCalculator;
use Pulsar\Extension\Accessibility\Contrast\ParsedColor;

final class LuminanceCalculatorTest extends TestCase
{
    private LuminanceCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new LuminanceCalculator();
    }

    #[Test]
    public function black_luminance_is_zero(): void
    {
        $black = new ParsedColor(0, 0, 0);

        $luminance = $this->calculator->relativeLuminance($black);

        self::assertEqualsWithDelta(0.0, $luminance, 0.001);
    }

    #[Test]
    public function white_luminance_is_one(): void
    {
        $white = new ParsedColor(255, 255, 255);

        $luminance = $this->calculator->relativeLuminance($white);

        self::assertEqualsWithDelta(1.0, $luminance, 0.001);
    }

    #[Test]
    public function black_vs_white_contrast_is_21(): void
    {
        $black = new ParsedColor(0, 0, 0);
        $white = new ParsedColor(255, 255, 255);

        $ratio = $this->calculator->contrastRatio($black, $white);

        self::assertEqualsWithDelta(21.0, $ratio, 0.1);
    }

    #[Test]
    public function gray_vs_white_contrast_around_4_54(): void
    {
        // #767676 is the threshold gray that just passes AA for normal text
        $gray = new ParsedColor(0x76, 0x76, 0x76);
        $white = new ParsedColor(255, 255, 255);

        $ratio = $this->calculator->contrastRatio($gray, $white);

        self::assertEqualsWithDelta(4.54, $ratio, 0.1);
    }

    #[Test]
    public function contrast_ratio_is_symmetric(): void
    {
        $red = new ParsedColor(255, 0, 0);
        $blue = new ParsedColor(0, 0, 255);

        $ratio1 = $this->calculator->contrastRatio($red, $blue);
        $ratio2 = $this->calculator->contrastRatio($blue, $red);

        self::assertEqualsWithDelta($ratio1, $ratio2, 0.001);
    }

    #[Test]
    public function identical_colors_have_contrast_ratio_1(): void
    {
        $color = new ParsedColor(128, 128, 128);

        $ratio = $this->calculator->contrastRatio($color, $color);

        self::assertEqualsWithDelta(1.0, $ratio, 0.001);
    }
}
