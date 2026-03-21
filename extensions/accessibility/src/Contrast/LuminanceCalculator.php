<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Contrast;

use Pulsar\Api\Api;

/**
 * Implements WCAG 2.1 relative luminance and contrast ratio calculations.
 *
 * @see https://www.w3.org/TR/WCAG21/#dfn-relative-luminance
 * @see https://www.w3.org/TR/WCAG21/#dfn-contrast-ratio
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class LuminanceCalculator
{
    /**
     * Calculates the relative luminance of a color per WCAG 2.1.
     *
     * The sRGB to linear conversion uses the IEC 61966-2-1 transfer function:
     * - If sRGB <= 0.04045: linear = sRGB / 12.92
     * - Otherwise: linear = ((sRGB + 0.055) / 1.055) ^ 2.4
     */
    public function relativeLuminance(ParsedColor $color): float
    {
        $rLinear = $this->srgbToLinear((float) $color->r / 255.0);
        $gLinear = $this->srgbToLinear((float) $color->g / 255.0);
        $bLinear = $this->srgbToLinear((float) $color->b / 255.0);

        return 0.2126 * $rLinear + 0.7152 * $gLinear + 0.0722 * $bLinear;
    }

    /**
     * Calculates the WCAG contrast ratio between two colors.
     *
     * Returns a value between 1.0 (identical) and 21.0 (black on white).
     * The formula is (L1 + 0.05) / (L2 + 0.05) where L1 >= L2.
     */
    public function contrastRatio(ParsedColor $fg, ParsedColor $bg): float
    {
        $l1 = $this->relativeLuminance($fg);
        $l2 = $this->relativeLuminance($bg);

        $lighter = max($l1, $l2);
        $darker = min($l1, $l2);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function srgbToLinear(float $channel): float
    {
        if ($channel <= 0.04045) {
            return $channel / 12.92;
        }

        return (($channel + 0.055) / 1.055) ** 2.4;
    }
}
