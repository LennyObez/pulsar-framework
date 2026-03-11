<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use function hash_equals;
use function strtoupper;

/**
 * Verifies recovery codes using constant-time comparison.
 */
final readonly class RecoveryCodeVerifier
{
    /**
     * Verify a recovery code against a set of valid codes.
     *
     * Uses constant-time comparison to prevent timing attacks.
     * Returns the index of the matched code, or -1 if no match.
     *
     * F12.5: walks the entire list even after a match so the
     * timing of a successful verify does not leak the matched
     * index. Each `hash_equals` comparison is constant-time per
     * pair, but an early-return broke that guarantee at the
     * list level — a 5th-position match completed faster than a
     * 200th-position match, leaking ~log2(N) bits of state per
     * timing observation.
     *
     * @param list<string> $validCodes
     */
    public function verify(string $code, array $validCodes): int
    {
        $normalizedCode = strtoupper($code);
        $matchedIndex = -1;

        foreach ($validCodes as $index => $validCode) {
            if (hash_equals(strtoupper($validCode), $normalizedCode) && $matchedIndex === -1) {
                $matchedIndex = $index;
            }
        }

        return $matchedIndex;
    }
}
