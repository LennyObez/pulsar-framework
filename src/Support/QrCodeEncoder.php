<?php

declare(strict_types=1);

namespace Pulsar\Support;

use InvalidArgumentException;
use Pulsar\Api\Api;

use function abs;
use function array_fill;
use function array_map;
use function array_pad;
use function array_slice;
use function count;
use function intdiv;
use function max;
use function min;
use function ord;
use function range;
use function sprintf;
use function strlen;

use const PHP_INT_MAX;

/**
 * QR Code encoder producing SVG output.
 *
 * Implements ISO 18004 QR code generation with the following features:
 * - Byte mode encoding (supports full UTF-8)
 * - Error correction level M (15% recovery)
 * - Automatic version selection based on data length (versions 1-10)
 * - SVG output with configurable module size and quiet zone
 *
 * This is a self-contained implementation with no external dependencies.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class QrCodeEncoder
{
    /**
     * Total data codewords per version for EC level M (versions 1-10).
     * @var list<int>
     */
    private const array VERSION_DATA_CODEWORDS = [
        16, 28, 44, 64, 86, 108, 130, 156, 182, 212,
    ];

    /**
     * Error correction codewords per block for EC level M (versions 1-10).
     * @var list<int>
     */
    private const array VERSION_EC_CODEWORDS_PER_BLOCK = [
        10, 16, 26, 18, 24, 16, 18, 22, 22, 26,
    ];

    /**
     * Number of EC blocks for EC level M (versions 1-10).
     * @var list<int>
     */
    private const array VERSION_EC_BLOCKS = [
        1, 1, 1, 2, 2, 4, 4, 4, 4, 6,
    ];

    /**
     * Alignment pattern center positions per version (versions 2-10).
     * @var array<int, list<int>>
     */
    private const array ALIGNMENT_POSITIONS = [
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 52],
    ];

    /**
     * Format info strings for mask patterns 0-7 at EC level M.
     * Pre-computed BCH encoded, XOR'd with 101010000010010.
     * @var list<int>
     */
    private const array FORMAT_INFO = [
        0b101010000010010,
        0b101000100100101,
        0b101111001111100,
        0b101101101001011,
        0b100010111111001,
        0b100000011001110,
        0b100111110010111,
        0b100101010100000,
    ];

    /**
     * Encode data as a QR code and return an SVG string.
     *
     * @param int $moduleSize Size of each module (pixel) in SVG units
     * @param int $quietZone Number of quiet zone modules around the QR code
     */
    public function encode(string $data, int $moduleSize = 4, int $quietZone = 4): string
    {
        // Determine version based on data length (byte mode)
        $version = $this->selectVersion(strlen($data));
        $size = 4 * $version + 17;

        // Encode data into bit stream
        $bitStream = $this->encodeData($data, $version);

        // Add error correction
        $codewords = $this->bitsToCodewords($bitStream);
        $ecCodewords = $this->computeErrorCorrection($codewords, $version);

        // Interleave and build final bit stream
        $finalBits = $this->interleave($codewords, $ecCodewords, $version);

        // Create the QR matrix
        $matrix = $this->createMatrix($size);
        $reserved = $this->createMatrix($size);

        // Place function patterns
        $this->placeFinderPatterns($matrix, $reserved, $size);
        $this->placeAlignmentPatterns($matrix, $reserved, $version, $size);
        $this->placeTimingPatterns($matrix, $reserved, $size);
        $this->placeDarkModule($matrix, $reserved, $version);
        $this->reserveFormatArea($reserved, $size);

        if ($version >= 7) {
            $this->reserveVersionArea($reserved, $size);
        }

        // Place data bits
        $this->placeDataBits($matrix, $reserved, $finalBits, $size);

        // Apply best mask
        $bestMask = $this->selectBestMask($matrix, $reserved, $size);
        $this->applyMask($matrix, $reserved, $bestMask, $size);

        // Place format information
        $this->placeFormatInfo($matrix, $bestMask, $size);

        return $this->renderSvg($matrix, $size, $moduleSize, $quietZone);
    }

    /**
     * Select the minimum QR version that can hold the data (byte mode, EC level M).
     */
    private function selectVersion(int $dataLength): int
    {
        foreach (self::VERSION_DATA_CODEWORDS as $v => $totalDataCw) {
            // Byte mode: 4-bit mode indicator + character count indicator + data
            $charCountBits = $v < 9 ? 8 : 16; // Versions 1-9: 8 bits, 10+: 16 bits
            $headerBits = 4 + $charCountBits;
            $dataBits = $dataLength * 8;
            $totalBits = $headerBits + $dataBits;

            // Available data bits = data codewords * 8
            $ecBlocks = self::VERSION_EC_BLOCKS[$v] ?? 0;
            $ecPerBlock = self::VERSION_EC_CODEWORDS_PER_BLOCK[$v] ?? 0;
            $availableCodewords = $totalDataCw - ($ecBlocks * $ecPerBlock);

            if ($availableCodewords * 8 >= $totalBits) {
                return $v + 1;
            }
        }

        throw new InvalidArgumentException(
            sprintf('Data too large for QR code (max supported: version 10). Data length: %d bytes', $dataLength),
        );
    }

    /**
     * Encode data in byte mode into a bit stream.
     *
     * @return list<int> Bits (0 or 1)
     */
    private function encodeData(string $data, int $version): array
    {
        // Mode indicator: 0100 (byte mode)
        /** @var list<int> $bits */
        $bits = [0, 1, 0, 0];

        // Character count indicator
        $charCountBits = $version <= 9 ? 8 : 16;
        $length = strlen($data);

        for ($i = $charCountBits - 1; $i >= 0; $i--) {
            $bits[] = ($length >> $i) & 1;
        }

        // Data bytes
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($data[$i]);

            for ($j = 7; $j >= 0; $j--) {
                $bits[] = ($byte >> $j) & 1;
            }
        }

        // Terminator (up to 4 zero bits)
        $vIdx = $version - 1;
        $ecBlocks = self::VERSION_EC_BLOCKS[$vIdx] ?? 0;
        $ecPerBlock = self::VERSION_EC_CODEWORDS_PER_BLOCK[$vIdx] ?? 0;
        $totalCw = self::VERSION_DATA_CODEWORDS[$vIdx] ?? 0;
        $totalDataCodewords = $totalCw - ($ecBlocks * $ecPerBlock);
        $totalDataBits = $totalDataCodewords * 8;
        $terminatorBits = min(4, $totalDataBits - count($bits));

        for ($i = 0; $i < $terminatorBits; $i++) {
            $bits[] = 0;
        }

        // Pad to byte boundary
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        // Pad with alternating 11101100 (236) and 00010001 (17)
        $padBytes = [0xEC, 0x11];
        $padIndex = 0;

        while (count($bits) < $totalDataBits) {
            $byte = $padBytes[$padIndex % 2];

            for ($j = 7; $j >= 0; $j--) {
                $bits[] = ($byte >> $j) & 1;
            }

            $padIndex++;
        }

        /** @var list<int> $bits */
        return $bits;
    }

    /**
     * Convert a bit stream to codewords (bytes).
     *
     * @param list<int> $bits
     * @return list<int>
     */
    private function bitsToCodewords(array $bits): array
    {
        $codewords = [];

        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;

            for ($j = 0; $j < 8 && ($i + $j) < count($bits); $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
            }

            $codewords[] = $byte;
        }

        return $codewords;
    }

    /**
     * Compute Reed-Solomon error correction codewords.
     *
     * @param list<int> $data
     * @return list<list<int>> Error correction codewords per block
     */
    private function computeErrorCorrection(array $data, int $version): array
    {
        $vIdx = $version - 1;
        $ecBlocks = self::VERSION_EC_BLOCKS[$vIdx] ?? 1;
        $ecPerBlock = self::VERSION_EC_CODEWORDS_PER_BLOCK[$vIdx] ?? 0;
        $totalData = count($data);
        $dataPerBlock = intdiv($totalData, $ecBlocks);
        $extraData = $totalData % $ecBlocks;

        $generator = $this->rsGeneratorPoly($ecPerBlock);
        $result = [];
        $offset = 0;

        for ($b = 0; $b < $ecBlocks; $b++) {
            $blockSize = $dataPerBlock + ($b < $extraData ? 1 : 0);
            $block = array_slice($data, $offset, $blockSize);
            $offset += $blockSize;

            $result[] = $this->rsEncode($block, $generator, $ecPerBlock);
        }

        return $result;
    }

    /**
     * Generate Reed-Solomon generator polynomial.
     *
     * @return list<int> Generator polynomial coefficients in GF(256)
     */
    private function rsGeneratorPoly(int $degree): array
    {
        /** @var list<int> $poly */
        $poly = [1];

        for ($i = 0; $i < $degree; $i++) {
            $len = count($poly);
            /** @var list<int> $newPoly */
            $newPoly = array_fill(0, $len + 1, 0);

            for ($j = 0; $j < $len; $j++) {
                $newPoly[$j] ^= $poly[$j];
                $newPoly[$j + 1] ^= $this->gfMul($poly[$j], $this->gfExp($i));
            }

            $poly = $newPoly;
        }

        /** @var list<int> $poly */
        return $poly;
    }

    /**
     * Reed-Solomon encode a data block.
     *
     * @param list<int> $data
     * @param list<int> $generator
     * @return list<int>
     */
    private function rsEncode(array $data, array $generator, int $ecCount): array
    {
        /** @var list<int> $result */
        $result = array_pad($data, count($data) + $ecCount, 0);
        $dataCount = count($data);
        $genCount = count($generator);

        for ($i = 0; $i < $dataCount; $i++) {
            $coef = $result[$i];

            if ($coef !== 0) {
                for ($j = 1; $j < $genCount; $j++) {
                    $result[$i + $j] ^= $this->gfMul($generator[$j], $coef);
                }
            }
        }

        return array_slice($result, $dataCount);
    }

    /**
     * GF(256) multiplication using log/exp tables.
     */
    private function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return $this->gfExp(($this->gfLog($a) + $this->gfLog($b)) % 255);
    }

    /**
     * GF(256) exponentiation (alpha^n).
     */
    private function gfExp(int $n): int
    {
        /** @var array<int<0, 255>, int>|null $expTable */
        static $expTable = null;

        if ($expTable === null) {
            $expTable = [];
            $val = 1;

            for ($i = 0; $i < 256; $i++) {
                $expTable[$i] = $val;
                $val <<= 1;

                if ($val >= 256) {
                    $val ^= 0x11D; // Primitive polynomial for GF(256)
                }
            }
        }

        $idx = (($n % 255) + 255) % 255;

        return $expTable[$idx] ?? 0;
    }

    /**
     * GF(256) logarithm.
     */
    private function gfLog(int $n): int
    {
        /** @var array<int<0, 255>, int>|null $logTable */
        static $logTable = null;

        if ($logTable === null) {
            $logTable = [];
            $val = 1;

            for ($i = 0; $i < 255; $i++) {
                $logTable[$val] = $i;
                $val <<= 1;

                if ($val >= 256) {
                    $val ^= 0x11D;
                }
            }
        }

        return $logTable[$n] ?? 0;
    }

    /**
     * Interleave data and EC codewords.
     *
     * @param list<int> $dataCodewords
     * @param list<list<int>> $ecBlocks
     * @return list<int> Final bit stream
     */
    private function interleave(array $dataCodewords, array $ecBlocks, int $version): array
    {
        $numBlocks = self::VERSION_EC_BLOCKS[$version - 1] ?? 1;
        $totalData = count($dataCodewords);
        $dataPerBlock = intdiv($totalData, $numBlocks);
        $extraData = $totalData % $numBlocks;

        // Split data into blocks
        $dataBlocks = [];
        $offset = 0;

        for ($b = 0; $b < $numBlocks; $b++) {
            $blockSize = $dataPerBlock + ($b < $extraData ? 1 : 0);
            $dataBlocks[] = array_slice($dataCodewords, $offset, $blockSize);
            $offset += $blockSize;
        }

        // Interleave data codewords
        $interleaved = [];
        $lengths = array_map(count(...), $dataBlocks);
        $maxDataLen = $lengths !== [] ? max($lengths) : 0;

        for ($i = 0; $i < $maxDataLen; $i++) {
            for ($b = 0; $b < $numBlocks; $b++) {
                if (isset($dataBlocks[$b][$i])) {
                    $interleaved[] = $dataBlocks[$b][$i];
                }
            }
        }

        // Interleave EC codewords
        $ecLengths = array_map(count(...), $ecBlocks);
        $maxEcLen = $ecLengths !== [] ? max($ecLengths) : 0;

        for ($i = 0; $i < $maxEcLen; $i++) {
            for ($b = 0; $b < $numBlocks; $b++) {
                if (isset($ecBlocks[$b][$i])) {
                    $interleaved[] = $ecBlocks[$b][$i];
                }
            }
        }

        // Convert to bits
        $bits = [];

        foreach ($interleaved as $byte) {
            for ($j = 7; $j >= 0; $j--) {
                $bits[] = ($byte >> $j) & 1;
            }
        }

        return $bits;
    }

    /**
     * Create an empty QR matrix.
     *
     * @return list<list<int|null>> 2D array where null = unset, 0 = white, 1 = dark
     */
    private function createMatrix(int $size): array
    {
        /** @var list<list<int|null>> $matrix */
        $matrix = [];

        for ($r = 0; $r < $size; $r++) {
            /** @var list<int|null> $row */
            $row = array_fill(0, $size, null);
            $matrix[$r] = $row;
        }

        return $matrix;
    }

    /**
     * Place 7x7 finder patterns in three corners.
     *
     * @param array<int, array<int, int|null>> &$matrix
     * @param array<int, array<int, int|null>> &$reserved
     */
    private function placeFinderPatterns(array &$matrix, array &$reserved, int $size): void
    {
        $positions = [[0, 0], [0, $size - 7], [$size - 7, 0]];

        foreach ($positions as [$startRow, $startCol]) {
            for ($r = 0; $r < 7; $r++) {
                for ($c = 0; $c < 7; $c++) {
                    $row = $startRow + $r;
                    $col = $startCol + $c;

                    // Finder pattern: outer ring dark, inner ring white, center 3x3 dark
                    $isDark = ($r === 0 || $r === 6 || $c === 0 || $c === 6)
                        || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);

                    $matrix[$row][$col] = $isDark ? 1 : 0;
                    $reserved[$row][$col] = 1;
                }
            }

            // Separator (one module white border)
            for ($i = -1; $i <= 7; $i++) {
                $this->setIfInBounds($matrix, $reserved, $startRow - 1, $startCol + $i, $size);
                $this->setIfInBounds($matrix, $reserved, $startRow + 7, $startCol + $i, $size);
                $this->setIfInBounds($matrix, $reserved, $startRow + $i, $startCol - 1, $size);
                $this->setIfInBounds($matrix, $reserved, $startRow + $i, $startCol + 7, $size);
            }
        }
    }

    /**
     * @param array<int, array<int, int|null>> &$matrix
     * @param array<int, array<int, int|null>> &$reserved
     */
    private function setIfInBounds(array &$matrix, array &$reserved, int $row, int $col, int $size): void
    {
        if ($row >= 0 && $row < $size && $col >= 0 && $col < $size) {
            $matrix[$row][$col] = 0;
            $reserved[$row][$col] = 1;
        }
    }

    /**
     * Place alignment patterns.
     *
     * @param array<int, array<int, int|null>> &$matrix
     * @param array<int, array<int, int|null>> &$reserved
     */
    private function placeAlignmentPatterns(array &$matrix, array &$reserved, int $version, int $size): void
    {
        if ($version < 2) {
            return;
        }

        $positions = self::ALIGNMENT_POSITIONS[$version] ?? [];

        foreach ($positions as $row) {
            foreach ($positions as $col) {
                // Skip if overlapping with finder patterns
                if ($reserved[$row][$col] !== null) {
                    continue;
                }

                // 5x5 alignment pattern
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $isDark = ($r === -2 || $r === 2 || $c === -2 || $c === 2)
                            || ($r === 0 && $c === 0);

                        $matrix[$row + $r][$col + $c] = $isDark ? 1 : 0;
                        $reserved[$row + $r][$col + $c] = 1;
                    }
                }
            }
        }
    }

    /**
     * Place timing patterns (row 6, column 6).
     *
     * @param array<int, array<int, int|null>> &$matrix
     * @param array<int, array<int, int|null>> &$reserved
     */
    private function placeTimingPatterns(array &$matrix, array &$reserved, int $size): void
    {
        for ($i = 8; $i < $size - 8; $i++) {
            $value = ($i % 2 === 0) ? 1 : 0;

            if (($reserved[6][$i] ?? null) === null) {
                $matrix[6][$i] = $value;
                $reserved[6][$i] = 1;
            }

            if ($reserved[$i][6] === null) {
                $matrix[$i][6] = $value;
                $reserved[$i][6] = 1;
            }
        }
    }

    /**
     * Place the dark module (always at version * 4 + 13, row 8).
     *
     * @param array<int, array<int, int|null>> &$matrix
     * @param array<int, array<int, int|null>> &$reserved
     */
    private function placeDarkModule(array &$matrix, array &$reserved, int $version): void
    {
        $row = (4 * $version) + 9;
        $matrix[$row][8] = 1;
        $reserved[$row][8] = 1;
    }

    /**
     * Reserve cells for format information (not filled yet).
     *
     * @param array<int, array<int, int|null>> &$reserved
     */
    private function reserveFormatArea(array &$reserved, int $size): void
    {
        // Around top-left finder
        for ($i = 0; $i <= 8; $i++) {
            $reserved[8][$i] = 1;
            $reserved[$i][8] = 1;
        }

        // Around bottom-left finder
        for ($i = $size - 8; $i < $size; $i++) {
            $reserved[$i][8] = 1;
        }

        // Around top-right finder
        for ($i = $size - 8; $i < $size; $i++) {
            $reserved[8][$i] = 1;
        }
    }

    /**
     * Reserve cells for version information (versions 7+).
     *
     * @param array<int, array<int, int|null>> &$reserved
     */
    private function reserveVersionArea(array &$reserved, int $size): void
    {
        // Bottom-left block
        for ($r = $size - 11; $r <= $size - 9; $r++) {
            for ($c = 0; $c <= 5; $c++) {
                $reserved[$r][$c] = 1;
            }
        }

        // Top-right block
        for ($r = 0; $r <= 5; $r++) {
            for ($c = $size - 11; $c <= $size - 9; $c++) {
                $reserved[$r][$c] = 1;
            }
        }
    }

    /**
     * Place data bits in the matrix using the standard zigzag pattern.
     *
     * @param array<int, array<int, int|null>> &$matrix
     * @param array<int, array<int, int|null>> $reserved
     * @param list<int> $bits
     */
    private function placeDataBits(array &$matrix, array $reserved, array $bits, int $size): void
    {
        $bitIndex = 0;
        $totalBits = count($bits);

        // Data placement uses right-to-left column pairs, zigzagging up and down
        $col = $size - 1;

        while ($col >= 0) {
            // Skip timing pattern column
            if ($col === 6) {
                $col--;

                continue;
            }

            // Determine direction: even column-pair index goes upward, odd goes downward
            $upward = (intdiv(($col < 6 ? $col + 1 : $col), 2) % 2) === 0;

            $rows = $upward ? range($size - 1, 0, -1) : range(0, $size - 1);

            foreach ($rows as $row) {
                for ($offset = 0; $offset <= 1; $offset++) {
                    $c = $col - $offset;

                    if ($c < 0 || $reserved[$row][$c] !== null) {
                        continue;
                    }

                    if ($bitIndex < $totalBits) {
                        $matrix[$row][$c] = (int) $bits[$bitIndex];
                        $bitIndex++;
                    } else {
                        $matrix[$row][$c] = 0;
                    }
                }
            }

            $col -= 2;
        }
    }

    /**
     * Select the best masking pattern (lowest penalty score).
     *
     * @param array<int, array<int, int|null>> $matrix
     * @param array<int, array<int, int|null>> $reserved
     */
    private function selectBestMask(array $matrix, array $reserved, int $size): int
    {
        $bestMask = 0;
        $bestScore = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $testMatrix = $matrix;
            $this->applyMask($testMatrix, $reserved, $mask, $size);
            $score = $this->computePenalty($testMatrix, $size);

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestMask = $mask;
            }
        }

        return $bestMask;
    }

    /**
     * Apply a mask pattern to the matrix.
     *
     * @param array<int, array<int, int|null>> &$matrix
     * @param array<int, array<int, int|null>> $reserved
     */
    private function applyMask(array &$matrix, array $reserved, int $maskPattern, int $size): void
    {
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($reserved[$r][$c] !== null) {
                    continue;
                }

                $shouldFlip = match ($maskPattern) {
                    0 => ($r + $c) % 2 === 0,
                    1 => $r % 2 === 0,
                    2 => $c % 3 === 0,
                    3 => ($r + $c) % 3 === 0,
                    4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
                    5 => ($r * $c) % 2 + ($r * $c) % 3 === 0,
                    6 => (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0,
                    7 => (($r + $c) % 2 + ($r * $c) % 3) % 2 === 0,
                    default => false,
                };

                if ($shouldFlip) {
                    $matrix[$r][$c] = ($matrix[$r][$c] ?? 0) ^ 1;
                }
            }
        }
    }

    /**
     * Compute the penalty score for a masked QR matrix.
     *
     * Implements all four penalty rules from ISO 18004.
     *
     * @param array<int, array<int, int|null>> $matrix
     */
    private function computePenalty(array $matrix, int $size): int
    {
        $penalty = 0;

        // Rule 1: Adjacent modules in row/column (same color, 5+ consecutive)
        for ($r = 0; $r < $size; $r++) {
            $count = 1;

            for ($c = 1; $c < $size; $c++) {
                if ($matrix[$r][$c] === $matrix[$r][$c - 1]) {
                    $count++;
                } else {
                    if ($count >= 5) {
                        $penalty += $count - 2;
                    }

                    $count = 1;
                }
            }

            if ($count >= 5) {
                $penalty += $count - 2;
            }
        }

        for ($c = 0; $c < $size; $c++) {
            $count = 1;

            for ($r = 1; $r < $size; $r++) {
                if ($matrix[$r][$c] === $matrix[$r - 1][$c]) {
                    $count++;
                } else {
                    if ($count >= 5) {
                        $penalty += $count - 2;
                    }

                    $count = 1;
                }
            }

            if ($count >= 5) {
                $penalty += $count - 2;
            }
        }

        // Rule 2: 2x2 blocks of same color
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $val = $matrix[$r][$c];

                if (
                    $val === $matrix[$r][$c + 1]
                    && $val === $matrix[$r + 1][$c]
                    && $val === $matrix[$r + 1][$c + 1]
                ) {
                    $penalty += 3;
                }
            }
        }

        // Rule 3: Finder-like patterns (1011101 preceded/followed by 4 whites)
        $pattern1 = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        $pattern2 = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];

        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c <= $size - 11; $c++) {
                $match1 = true;
                $match2 = true;

                for ($i = 0; $i < 11; $i++) {
                    $val = $matrix[$r][$c + $i] ?? 0;

                    if ($val !== $pattern1[$i]) {
                        $match1 = false;
                    }

                    if ($val !== $pattern2[$i]) {
                        $match2 = false;
                    }
                }

                if ($match1 || $match2) {
                    $penalty += 40;
                }
            }
        }

        for ($c = 0; $c < $size; $c++) {
            for ($r = 0; $r <= $size - 11; $r++) {
                $match1 = true;
                $match2 = true;

                for ($i = 0; $i < 11; $i++) {
                    $val = $matrix[$r + $i][$c] ?? 0;

                    if ($val !== $pattern1[$i]) {
                        $match1 = false;
                    }

                    if ($val !== $pattern2[$i]) {
                        $match2 = false;
                    }
                }

                if ($match1 || $match2) {
                    $penalty += 40;
                }
            }
        }

        // Rule 4: Proportion of dark modules
        $totalModules = $size * $size;
        $darkCount = 0;

        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if (($matrix[$r][$c] ?? 0) === 1) {
                    $darkCount++;
                }
            }
        }

        $percentage = (int) (($darkCount * 100) / $totalModules);
        $prevMultiple = intdiv($percentage - ($percentage % 5), 5);
        $nextMultiple = $prevMultiple + 1;
        $penalty += min(
            abs($prevMultiple * 5 - 50) * 2,
            abs($nextMultiple * 5 - 50) * 2,
        );

        return $penalty;
    }

    /**
     * Place format information bits in the matrix.
     *
     * @param array<int, array<int, int|null>> &$matrix
     */
    private function placeFormatInfo(array &$matrix, int $maskPattern, int $size): void
    {
        $formatInfo = self::FORMAT_INFO[$maskPattern];

        // Place around top-left finder
        /** @var list<array{0: int, 1: int}> $positions */
        $positions = [
            // Horizontal (row 8, columns 0-7 then $size-8 to $size-1)
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5],
            [8, 7], [8, 8],
            // Vertical (column 8, rows 7 then 5 to 0)
            [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8],
        ];

        foreach ($positions as $i => [$row, $col]) {
            $bit = ($formatInfo >> (14 - $i)) & 1;
            $matrix[$row][$col] = $bit;
        }

        // Place around bottom-left and top-right finders
        /** @var list<array{0: int, 1: int}> $bottomLeftPositions */
        $bottomLeftPositions = [
            [$size - 1, 8], [$size - 2, 8], [$size - 3, 8], [$size - 4, 8],
            [$size - 5, 8], [$size - 6, 8], [$size - 7, 8],
        ];

        /** @var list<array{0: int, 1: int}> $topRightPositions */
        $topRightPositions = [
            [8, $size - 8], [8, $size - 7], [8, $size - 6], [8, $size - 5],
            [8, $size - 4], [8, $size - 3], [8, $size - 2], [8, $size - 1],
        ];

        foreach ($bottomLeftPositions as $i => [$row, $col]) {
            $bit = ($formatInfo >> $i) & 1;
            $matrix[$row][$col] = $bit;
        }

        foreach ($topRightPositions as $i => [$row, $col]) {
            $bit = ($formatInfo >> (14 - $i)) & 1;
            $matrix[$row][$col] = $bit;
        }
    }

    /**
     * Render the QR matrix as an SVG string.
     *
     * @param array<int, array<int, int|null>> $matrix
     */
    private function renderSvg(array $matrix, int $size, int $moduleSize, int $quietZone): string
    {
        $totalSize = ($size + 2 * $quietZone) * $moduleSize;

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d">',
            $totalSize,
            $totalSize,
            $totalSize,
            $totalSize,
        );

        // White background
        $svg .= sprintf(
            '<rect width="%d" height="%d" fill="#fff"/>',
            $totalSize,
            $totalSize,
        );

        // Dark modules as a single path for efficiency
        $pathData = '';

        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if (($matrix[$r][$c] ?? 0) === 1) {
                    $x = ($c + $quietZone) * $moduleSize;
                    $y = ($r + $quietZone) * $moduleSize;
                    $pathData .= sprintf('M%d,%dh%dv%dh-%dz', $x, $y, $moduleSize, $moduleSize, $moduleSize);
                }
            }
        }

        if ($pathData !== '') {
            $svg .= sprintf('<path d="%s" fill="#000"/>', $pathData);
        }

        $svg .= '</svg>';

        return $svg;
    }
}
