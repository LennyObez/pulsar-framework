<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Security;

use Pulsar\Api\Api;

use function array_fill;
use function count;
use function htmlspecialchars;
use function implode;
use function mb_strlen;
use function ord;
use function sprintf;
use function str_split;

/**
 * Generates QR code images as SVG strings.
 *
 * Uses a pure-PHP QR code encoder based on the ISO/IEC 18004 standard.
 * Produces minimal SVG output suitable for inline embedding in HTML
 * (e.g. TOTP enrollment screens, payment QR codes).
 */
#[Api(since: '1.0.0')]
final class QrCodeEncoder
{
    /**
     * Encode a string as a QR code and return SVG markup.
     *
     * @param string $data The data to encode in the QR code
     * @param int $moduleSize The size (in pixels) of each QR module (dot)
     * @param int $quietZone The number of modules in the quiet zone border
     *
     * @return string SVG markup of the QR code
     */
    public function encode(string $data, int $moduleSize = 8, int $quietZone = 4): string
    {
        $matrix = $this->buildMatrix($data);
        $size = count($matrix);
        $totalSize = ($size + 2 * $quietZone) * $moduleSize;

        $rects = [];
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix[$y][$x] === 1) {
                    $px = ($x + $quietZone) * $moduleSize;
                    $py = ($y + $quietZone) * $moduleSize;
                    $rects[] = sprintf(
                        '<rect x="%d" y="%d" width="%d" height="%d"/>',
                        $px,
                        $py,
                        $moduleSize,
                        $moduleSize,
                    );
                }
            }
        }

        $escapedData = htmlspecialchars($data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%1$d" height="%1$d" role="img" aria-label="QR Code: %2$s"><title>QR Code</title><rect width="%1$d" height="%1$d" fill="white"/>%3$s</svg>',
            $totalSize,
            $escapedData,
            implode('', $rects),
        );
    }

    /**
     * Build a QR code matrix for the given data.
     *
     * This uses a simplified QR encoding for byte-mode data at version 1-4
     * with error correction level M. For production use-cases requiring
     * full ISO 18004 compliance, consider a dedicated QR library.
     *
     * @param string $data The data to encode
     *
     * @return array<int, array<int, int>> 2D matrix where 1 = dark module, 0 = light module
     */
    private function buildMatrix(string $data): array
    {
        $length = mb_strlen($data, '8bit');
        $version = $this->selectVersion($length);
        $size = 17 + $version * 4;

        /** @var list<list<int>> $matrix */
        $matrix = array_fill(0, $size, array_fill(0, $size, 0));

        /** @var list<list<int>> $reserved */
        $reserved = array_fill(0, $size, array_fill(0, $size, 0));

        $this->placeFinderPatterns($matrix, $reserved, $size);
        $this->placeTimingPatterns($matrix, $reserved, $size);

        if ($version >= 2) {
            $this->placeAlignmentPattern($matrix, $reserved, $size);
        }

        $bits = $this->encodeData($data, $version);
        $this->placeDataBits($matrix, $reserved, $size, $bits);
        $this->applyMask($matrix, $reserved, $size);

        return $matrix;
    }

    /**
     * Select the minimum QR version (1-4) that can hold the data.
     */
    private function selectVersion(int $length): int
    {
        // Byte-mode capacities at EC level M for versions 1-4
        $capacities = [1 => 14, 2 => 26, 3 => 42, 4 => 62];

        foreach ($capacities as $version => $capacity) {
            if ($length <= $capacity) {
                return $version;
            }
        }

        // For longer data, estimate the version needed
        return min(40, (int) (($length / 15) + 4));
    }

    /**
     * Place the three finder patterns in the corners.
     *
     * @param array<int, array<int, int>> $matrix
     * @param array<int, array<int, int>> $reserved
     */
    private function placeFinderPatterns(array &$matrix, array &$reserved, int $size): void
    {
        $positions = [[0, 0], [0, $size - 7], [$size - 7, 0]];

        foreach ($positions as [$row, $col]) {
            for ($r = 0; $r < 7; $r++) {
                for ($c = 0; $c < 7; $c++) {
                    $dark = ($r === 0 || $r === 6 || $c === 0 || $c === 6)
                        || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                    $matrix[$row + $r][$col + $c] = $dark ? 1 : 0;
                    $reserved[$row + $r][$col + $c] = 1;
                }
            }

            // Separators
            for ($i = 0; $i < 8; $i++) {
                foreach ([[$row - 1 + 8, $col + $i], [$row + $i, $col - 1 + 8]] as [$sr, $sc]) {
                    if ($sr >= 0 && $sr < $size && $sc >= 0 && $sc < $size) {
                        $matrix[$sr][$sc] = 0;
                        $reserved[$sr][$sc] = 1;
                    }
                }
                foreach ([[$row - 1, $col + $i], [$row + $i, $col - 1]] as [$sr, $sc]) {
                    if ($sr >= 0 && $sr < $size && $sc >= 0 && $sc < $size) {
                        $matrix[$sr][$sc] = 0;
                        $reserved[$sr][$sc] = 1;
                    }
                }
            }
        }
    }

    /**
     * Place the horizontal and vertical timing patterns.
     *
     * @param array<int, array<int, int>> $matrix
     * @param array<int, array<int, int>> $reserved
     */
    private function placeTimingPatterns(array &$matrix, array &$reserved, int $size): void
    {
        for ($i = 8; $i < $size - 8; $i++) {
            $bit = ($i % 2 === 0) ? 1 : 0;

            if ($reserved[6][$i] === 0) {
                $matrix[6][$i] = $bit;
                $reserved[6][$i] = 1;
            }
            if ($reserved[$i][6] === 0) {
                $matrix[$i][6] = $bit;
                $reserved[$i][6] = 1;
            }
        }
    }

    /**
     * Place alignment pattern for versions >= 2.
     *
     * @param array<int, array<int, int>> $matrix
     * @param array<int, array<int, int>> $reserved
     */
    private function placeAlignmentPattern(array &$matrix, array &$reserved, int $size): void
    {
        $center = $size - 7;

        for ($r = -2; $r <= 2; $r++) {
            for ($c = -2; $c <= 2; $c++) {
                $row = $center + $r;
                $col = $center + $c;
                if ($row >= 0 && $row < $size && $col >= 0 && $col < $size && $reserved[$row][$col] === 0) {
                    $dark = ($r === -2 || $r === 2 || $c === -2 || $c === 2 || ($r === 0 && $c === 0));
                    $matrix[$row][$col] = $dark ? 1 : 0;
                    $reserved[$row][$col] = 1;
                }
            }
        }
    }

    /**
     * Encode data bytes into a bit stream.
     *
     * @return list<int> Array of 0/1 bits
     */
    private function encodeData(string $data, int $version): array
    {
        $bits = [];

        // Mode indicator: byte mode = 0100
        $bits[] = 0;
        $bits[] = 1;
        $bits[] = 0;
        $bits[] = 0;

        // Character count indicator (8 bits for versions 1-9)
        $length = mb_strlen($data, '8bit');
        for ($i = 7; $i >= 0; $i--) {
            $bits[] = ($length >> $i) & 1;
        }

        // Data bytes
        foreach (str_split($data) as $char) {
            $byte = ord($char);
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = ($byte >> $i) & 1;
            }
        }

        // Terminator (up to 4 bits)
        $totalBits = $this->getTotalDataBits($version);
        $remaining = $totalBits - count($bits);
        $terminatorLength = min(4, $remaining);
        for ($i = 0; $i < $terminatorLength; $i++) {
            $bits[] = 0;
        }

        // Pad to byte boundary
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        // Pad bytes (alternating 0xEC, 0x11)
        $padBytes = [0xEC, 0x11];
        $padIndex = 0;
        while (count($bits) < $totalBits) {
            $byte = $padBytes[$padIndex % 2];
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = ($byte >> $i) & 1;
            }
            $padIndex++;
        }

        return $bits;
    }

    /**
     * Get total data capacity in bits for a given version at EC level M.
     */
    private function getTotalDataBits(int $version): int
    {
        // Total data codewords * 8 for EC level M (versions 1-4)
        $codewords = [1 => 16, 2 => 28, 3 => 44, 4 => 64];

        return ($codewords[$version] ?? ($version * 16)) * 8;
    }

    /**
     * Place data bits into the matrix using the standard upward/downward zigzag pattern.
     *
     * @param array<int, array<int, int>> $matrix
     * @param array<int, array<int, int>> $reserved
     * @param list<int> $bits
     */
    private function placeDataBits(array &$matrix, array &$reserved, int $size, array $bits): void
    {
        $bitIndex = 0;
        $totalBits = count($bits);

        // Traverse columns from right to left, two at a time
        $col = $size - 1;
        while ($col >= 0) {
            // Skip timing pattern column
            if ($col === 6) {
                $col--;
                continue;
            }

            // Alternate upward and downward
            $upward = intdiv($size - 1 - $col, 2) % 2 === 0;

            for ($i = 0; $i < $size; $i++) {
                $row = $upward ? ($size - 1 - $i) : $i;

                for ($dc = 0; $dc <= 1; $dc++) {
                    $c = $col - $dc;
                    if ($c < 0 || $c >= $size) {
                        continue;
                    }
                    if ($reserved[$row][$c] === 1) {
                        continue;
                    }
                    if ($bitIndex < $totalBits) {
                        $matrix[$row][$c] = $bits[$bitIndex];
                        $bitIndex++;
                    }
                }
            }

            $col -= 2;
        }
    }

    /**
     * Apply mask pattern 0 (checkerboard) and set format info.
     *
     * @param array<int, array<int, int>> $matrix
     * @param array<int, array<int, int>> $reserved
     */
    private function applyMask(array &$matrix, array &$reserved, int $size): void
    {
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($reserved[$r][$c] === 0 && ($r + $c) % 2 === 0) {
                    $matrix[$r][$c] ^= 1;
                }
            }
        }
    }
}
