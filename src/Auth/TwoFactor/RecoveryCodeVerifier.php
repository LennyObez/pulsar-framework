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
     * @param list<string> $validCodes
     */
    public function verify(string $code, array $validCodes): int
    {
        $normalizedCode = strtoupper($code);

        foreach ($validCodes as $index => $validCode) {
            if (hash_equals(strtoupper($validCode), $normalizedCode)) {
                return $index;
            }
        }

        return -1;
    }
}
