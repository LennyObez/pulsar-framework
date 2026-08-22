<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Contrast;

use Pulsar\Api\Api;

use function count;
use function strlen;

/**
 * Parses CSS color values to normalized RGB.
 *
 * Supports hex (#RGB, #RRGGBB, #RRGGBBAA), rgb()/rgba(),
 * hsl()/hsla(), and named CSS colors.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ColorParser
{
    /** @var array<string, array{int, int, int, float}> */
    private const array NAMED_COLORS = [
        'black' => [0, 0, 0, 1.0],
        'white' => [255, 255, 255, 1.0],
        'red' => [255, 0, 0, 1.0],
        'green' => [0, 128, 0, 1.0],
        'blue' => [0, 0, 255, 1.0],
        'transparent' => [0, 0, 0, 0.0],
        'silver' => [192, 192, 192, 1.0],
        'gray' => [128, 128, 128, 1.0],
        'grey' => [128, 128, 128, 1.0],
        'maroon' => [128, 0, 0, 1.0],
        'yellow' => [255, 255, 0, 1.0],
        'olive' => [128, 128, 0, 1.0],
        'lime' => [0, 255, 0, 1.0],
        'aqua' => [0, 255, 255, 1.0],
        'cyan' => [0, 255, 255, 1.0],
        'teal' => [0, 128, 128, 1.0],
        'navy' => [0, 0, 128, 1.0],
        'fuchsia' => [255, 0, 255, 1.0],
        'magenta' => [255, 0, 255, 1.0],
        'purple' => [128, 0, 128, 1.0],
        'orange' => [255, 165, 0, 1.0],
        'pink' => [255, 192, 203, 1.0],
        'brown' => [165, 42, 42, 1.0],
        'coral' => [255, 127, 80, 1.0],
        'crimson' => [220, 20, 60, 1.0],
        'darkblue' => [0, 0, 139, 1.0],
        'darkgreen' => [0, 100, 0, 1.0],
        'darkred' => [139, 0, 0, 1.0],
        'gold' => [255, 215, 0, 1.0],
        'indigo' => [75, 0, 130, 1.0],
        'ivory' => [255, 255, 240, 1.0],
        'khaki' => [240, 230, 140, 1.0],
        'lavender' => [230, 230, 250, 1.0],
        'lightblue' => [173, 216, 230, 1.0],
        'lightgray' => [211, 211, 211, 1.0],
        'lightgrey' => [211, 211, 211, 1.0],
        'lightgreen' => [144, 238, 144, 1.0],
        'lightyellow' => [255, 255, 224, 1.0],
        'mintcream' => [245, 255, 250, 1.0],
        'mistyrose' => [255, 228, 225, 1.0],
        'moccasin' => [255, 228, 181, 1.0],
        'oldlace' => [253, 245, 230, 1.0],
        'orangered' => [255, 69, 0, 1.0],
        'orchid' => [218, 112, 214, 1.0],
        'peru' => [205, 133, 63, 1.0],
        'plum' => [221, 160, 221, 1.0],
        'salmon' => [250, 128, 114, 1.0],
        'sienna' => [160, 82, 45, 1.0],
        'skyblue' => [135, 206, 235, 1.0],
        'slategray' => [112, 128, 144, 1.0],
        'slategrey' => [112, 128, 144, 1.0],
        'snow' => [255, 250, 250, 1.0],
        'steelblue' => [70, 130, 180, 1.0],
        'tan' => [210, 180, 140, 1.0],
        'thistle' => [216, 191, 216, 1.0],
        'tomato' => [255, 99, 71, 1.0],
        'turquoise' => [64, 224, 208, 1.0],
        'violet' => [238, 130, 238, 1.0],
        'wheat' => [245, 222, 179, 1.0],
        'whitesmoke' => [245, 245, 245, 1.0],
        'yellowgreen' => [154, 205, 50, 1.0],
    ];

    public function parse(string $value): ParsedColor
    {
        $value = trim($value);

        if ($value === '') {
            throw InvalidColorException::forValue($value);
        }

        // Named colors (case-insensitive)
        $lower = strtolower($value);

        if (isset(self::NAMED_COLORS[$lower])) {
            [$r, $g, $b, $a] = self::NAMED_COLORS[$lower];

            return new ParsedColor($r, $g, $b, $a);
        }

        // Hex
        if (str_starts_with($value, '#')) {
            return $this->parseHex($value);
        }

        // rgb() / rgba()
        if (str_starts_with($lower, 'rgb')) {
            return $this->parseRgb($value);
        }

        // hsl() / hsla()
        if (str_starts_with($lower, 'hsl')) {
            return $this->parseHsl($value);
        }

        throw InvalidColorException::forValue($value);
    }

    private function parseHex(string $value): ParsedColor
    {
        $hex = substr($value, 1);

        return match (strlen($hex)) {
            // #RGB → #RRGGBB
            3 => new ParsedColor(
                (int) hexdec($hex[0] . $hex[0]),
                (int) hexdec($hex[1] . $hex[1]),
                (int) hexdec($hex[2] . $hex[2]),
            ),
            // #RGBA → #RRGGBBAA
            4 => new ParsedColor(
                (int) hexdec($hex[0] . $hex[0]),
                (int) hexdec($hex[1] . $hex[1]),
                (int) hexdec($hex[2] . $hex[2]),
                round((int) hexdec($hex[3] . $hex[3]) / 255, 2),
            ),
            // #RRGGBB
            6 => new ParsedColor(
                (int) hexdec(substr($hex, 0, 2)),
                (int) hexdec(substr($hex, 2, 2)),
                (int) hexdec(substr($hex, 4, 2)),
            ),
            // #RRGGBBAA
            8 => new ParsedColor(
                (int) hexdec(substr($hex, 0, 2)),
                (int) hexdec(substr($hex, 2, 2)),
                (int) hexdec(substr($hex, 4, 2)),
                round((int) hexdec(substr($hex, 6, 2)) / 255, 2),
            ),
            default => throw InvalidColorException::forValue($value),
        };
    }

    private function parseRgb(string $value): ParsedColor
    {
        // Match rgb(R, G, B) / rgba(R, G, B, A) with comma or space syntax
        $inner = $this->extractFunctionArgs($value, 'rgb');

        if ($inner === null) {
            $inner = $this->extractFunctionArgs($value, 'rgba');
        }

        if ($inner === null) {
            throw InvalidColorException::forValue($value);
        }

        $parts = $this->splitArgs($inner);

        if (count($parts) < 3 || count($parts) > 4) {
            throw InvalidColorException::forValue($value);
        }

        $r = $this->clampByte((int) round((float) $parts[0]));
        $g = $this->clampByte((int) round((float) $parts[1]));
        $b = $this->clampByte((int) round((float) $parts[2]));
        $a = isset($parts[3]) ? $this->parseAlpha($parts[3]) : 1.0;

        return new ParsedColor($r, $g, $b, $a);
    }

    private function parseHsl(string $value): ParsedColor
    {
        $inner = $this->extractFunctionArgs($value, 'hsl');

        if ($inner === null) {
            $inner = $this->extractFunctionArgs($value, 'hsla');
        }

        if ($inner === null) {
            throw InvalidColorException::forValue($value);
        }

        $parts = $this->splitArgs($inner);

        if (count($parts) < 3 || count($parts) > 4) {
            throw InvalidColorException::forValue($value);
        }

        $h = fmod((float) $parts[0], 360.0);

        if ($h < 0) {
            $h += 360.0;
        }

        $s = $this->parsePercentage($parts[1]);
        $l = $this->parsePercentage($parts[2]);
        $a = isset($parts[3]) ? $this->parseAlpha($parts[3]) : 1.0;

        [$r, $g, $b] = $this->hslToRgb($h / 360.0, $s, $l);

        return new ParsedColor($r, $g, $b, $a);
    }

    private function extractFunctionArgs(string $value, string $func): ?string
    {
        $pattern = '/^' . preg_quote($func, '/') . '\s*\(\s*(.*?)\s*\)$/si';

        if (preg_match($pattern, $value, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Splits CSS function arguments by comma or slash/space separators.
     *
     * @return list<string>
     */
    private function splitArgs(string $inner): array
    {
        // Try comma separation first
        if (str_contains($inner, ',')) {
            return array_map(trim(...), explode(',', $inner));
        }

        // Space-separated with optional slash for alpha: "R G B / A"
        $inner = (string) preg_replace('/\s*\/\s*/', ' ', $inner);
        $parts = preg_split('/\s+/', trim($inner));

        if ($parts === false) {
            return [];
        }

        /** @var list<string> $parts */
        return $parts;
    }

    private function parseAlpha(string $value): float
    {
        $value = trim($value);

        if (str_ends_with($value, '%')) {
            return max(0.0, min(1.0, (float) rtrim($value, '%') / 100.0));
        }

        return max(0.0, min(1.0, (float) $value));
    }

    private function parsePercentage(string $value): float
    {
        $value = trim($value);

        return max(0.0, min(1.0, (float) rtrim($value, '%') / 100.0));
    }

    /**
     * Converts HSL (all values 0.0-1.0) to RGB.
     *
     * @return array{int, int, int}
     */
    private function hslToRgb(float $h, float $s, float $l): array
    {
        if ($s === 0.0) {
            $v = (int) round($l * 255.0);

            return [$v, $v, $v];
        }

        $q = $l < 0.5 ? $l * (1.0 + $s) : $l + $s - $l * $s;
        $p = 2.0 * $l - $q;

        return [
            (int) round($this->hueToRgb($p, $q, $h + 1.0 / 3.0) * 255.0),
            (int) round($this->hueToRgb($p, $q, $h) * 255.0),
            (int) round($this->hueToRgb($p, $q, $h - 1.0 / 3.0) * 255.0),
        ];
    }

    private function hueToRgb(float $p, float $q, float $t): float
    {
        if ($t < 0.0) {
            $t += 1.0;
        }

        if ($t > 1.0) {
            $t -= 1.0;
        }

        if ($t < 1.0 / 6.0) {
            return $p + ($q - $p) * 6.0 * $t;
        }

        if ($t < 0.5) {
            return $q;
        }

        if ($t < 2.0 / 3.0) {
            return $p + ($q - $p) * (2.0 / 3.0 - $t) * 6.0;
        }

        return $p;
    }

    private function clampByte(int $value): int
    {
        return max(0, min(255, $value));
    }
}
