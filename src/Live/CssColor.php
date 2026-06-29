<?php

declare(strict_types=1);

namespace Pulsar\Live;

use Pulsar\Api\Internal;

use function preg_match;
use function trim;

/**
 * Strict CSS color sanitizer for values interpolated into `style` attributes
 * and `<style>` blocks.
 *
 * `htmlspecialchars()` neutralizes HTML metacharacters but leaves `;`, `}`,
 * `(`, `)`, `url`, and whitespace intact, so a config-supplied color such as
 * `#000; background: url(//evil)` can break out of a custom-property
 * declaration and inject arbitrary rules. This helper accepts only a strict
 * allowlist of safe CSS color syntaxes and replaces anything else with a
 * caller-supplied safe fallback, closing the CSS-injection vector at the
 * point of interpolation without altering output for legitimate colors.
 */
#[Internal]
final class CssColor
{
    /**
     * Hex colors: #RGB, #RGBA, #RRGGBB, #RRGGBBAA.
     */
    private const string HEX = '/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/';

    /**
     * Functional notations (rgb/rgba/hsl/hsla) restricted to digits, dots,
     * percent signs, commas, slashes, and inner whitespace — no `;`, `}`,
     * `url`, or nested function calls can survive.
     */
    private const string FUNCTIONAL = '/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9.,%\/\s]+\)$/i';

    /**
     * CSS named colors and the `transparent`/`currentColor` keywords are
     * purely alphabetic, so they cannot carry injection metacharacters.
     */
    private const string KEYWORD = '/^[a-zA-Z]+$/';

    /**
     * Return the color unchanged when it is a recognized safe CSS color,
     * otherwise return the supplied fallback.
     */
    public static function sanitize(string $color, string $fallback = '#4f46e5'): string
    {
        $candidate = trim($color);

        if (
            preg_match(self::HEX, $candidate) === 1
            || preg_match(self::FUNCTIONAL, $candidate) === 1
            || preg_match(self::KEYWORD, $candidate) === 1
        ) {
            return $candidate;
        }

        return $fallback;
    }
}
