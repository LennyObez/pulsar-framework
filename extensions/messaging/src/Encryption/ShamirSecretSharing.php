<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Encryption;

use InvalidArgumentException;
use Pulsar\Api\Api;

use function array_keys;
use function chr;
use function count;
use function ord;
use function random_bytes;
use function strlen;

/**
 * Shamir's Secret Sharing for organization/family recovery keys.
 *
 * Splits a secret into N shares where any M shares can reconstruct
 * the original secret, but fewer than M shares reveal nothing.
 *
 * Operates over GF(256) for byte-level splitting, making it compatible
 * with arbitrary binary secrets (encryption keys).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ShamirSecretSharing
{
    /**
     * Split a secret into N shares with a threshold of M.
     *
     * @param string $secret The secret to split (binary)
     * @param int $totalShares Total number of shares to create (N)
     * @param int $threshold Minimum shares required to reconstruct (M)
     * @return array<int, string> Share index (1-based) => share data (binary)
     */
    public function split(string $secret, int $totalShares, int $threshold): array
    {
        if ($threshold < 2) {
            throw new InvalidArgumentException('Threshold must be at least 2');
        }

        if ($totalShares < $threshold) {
            throw new InvalidArgumentException('Total shares must be >= threshold');
        }

        if ($totalShares > 255) {
            throw new InvalidArgumentException('Maximum 255 shares supported');
        }

        $secretLength = strlen($secret);
        $shares = [];

        for ($i = 1; $i <= $totalShares; $i++) {
            $shares[$i] = '';
        }

        // For each byte of the secret, create a random polynomial and evaluate
        for ($byteIndex = 0; $byteIndex < $secretLength; $byteIndex++) {
            $secretByte = ord($secret[$byteIndex]);

            // Generate random coefficients for polynomial (degree = threshold - 1)
            // coefficient[0] = secret byte, rest are random
            $coefficients = [$secretByte];
            $randomBytes = random_bytes($threshold - 1);
            for ($j = 0; $j < $threshold - 1; $j++) {
                $coefficients[] = ord($randomBytes[$j]);
            }

            // Evaluate polynomial at each share index (1..N) in GF(256)
            for ($shareIndex = 1; $shareIndex <= $totalShares; $shareIndex++) {
                $shares[$shareIndex] .= chr($this->evaluatePolynomial($coefficients, $shareIndex));
            }
        }

        return $shares;
    }

    /**
     * Reconstruct a secret from M or more shares.
     *
     * @param array<int, string> $shares Share index (1-based) => share data
     * @return string The reconstructed secret
     */
    public function reconstruct(array $shares): string
    {
        if (count($shares) < 2) {
            throw new InvalidArgumentException('At least 2 shares required for reconstruction');
        }

        $indices = array_keys($shares);
        $shareValues = array_values($shares);
        $secretLength = strlen($shareValues[0]);

        $secret = '';

        for ($byteIndex = 0; $byteIndex < $secretLength; $byteIndex++) {
            $points = [];
            foreach ($indices as $idx => $shareIndex) {
                $points[] = [$shareIndex, ord($shareValues[$idx][$byteIndex])];
            }

            $secret .= chr($this->lagrangeInterpolate($points));
        }

        return $secret;
    }

    /**
     * Evaluate a polynomial at x in GF(256).
     *
     * @param list<int> $coefficients Polynomial coefficients [a0, a1, ..., ak]
     * @param int $x Point to evaluate at
     * @return int Result in GF(256)
     */
    private function evaluatePolynomial(array $coefficients, int $x): int
    {
        $result = 0;
        $xPower = 1;

        foreach ($coefficients as $coefficient) {
            $result = $this->gfAdd($result, $this->gfMul($coefficient, $xPower));
            $xPower = $this->gfMul($xPower, $x);
        }

        return $result;
    }

    /**
     * Lagrange interpolation at x=0 in GF(256) to recover the secret.
     *
     * @param list<array{0: int, 1: int}> $points Array of [x, y] points
     * @return int The interpolated value at x=0
     */
    private function lagrangeInterpolate(array $points): int
    {
        $result = 0;
        $count = count($points);

        for ($i = 0; $i < $count; $i++) {
            $numerator = 1;
            $denominator = 1;

            for ($j = 0; $j < $count; $j++) {
                if ($i === $j) {
                    continue;
                }

                // Evaluate at x = 0, so numerator term is (0 - x_j) = x_j in GF(256)
                $numerator = $this->gfMul($numerator, $points[$j][0]);
                $denominator = $this->gfMul($denominator, $this->gfAdd($points[$i][0], $points[$j][0]));
            }

            $lagrangeCoeff = $this->gfMul($numerator, $this->gfInv($denominator));
            $result = $this->gfAdd($result, $this->gfMul($points[$i][1], $lagrangeCoeff));
        }

        return $result;
    }

    /**
     * Addition in GF(256) (XOR).
     */
    private function gfAdd(int $a, int $b): int
    {
        return $a ^ $b;
    }

    /**
     * Multiplication in GF(256) using the irreducible polynomial x^8 + x^4 + x^3 + x + 1.
     */
    private function gfMul(int $a, int $b): int
    {
        $result = 0;

        for ($i = 0; $i < 8; $i++) {
            if (($b & 1) !== 0) {
                $result ^= $a;
            }

            $highBit = $a & 0x80;
            $a = ($a << 1) & 0xFF;

            if ($highBit !== 0) {
                $a ^= 0x1B; // x^8 + x^4 + x^3 + x + 1
            }

            $b >>= 1;
        }

        return $result;
    }

    /**
     * Multiplicative inverse in GF(256) using extended Euclidean algorithm.
     */
    private function gfInv(int $a): int
    {
        if ($a === 0) {
            throw new InvalidArgumentException('Cannot invert zero in GF(256)');
        }

        // a^254 = a^(-1) in GF(256) by Fermat's little theorem
        $result = $a;
        for ($i = 0; $i < 6; $i++) {
            $result = $this->gfMul($result, $result);
            $result = $this->gfMul($result, $a);
        }
        $result = $this->gfMul($result, $result);

        return $result;
    }
}
