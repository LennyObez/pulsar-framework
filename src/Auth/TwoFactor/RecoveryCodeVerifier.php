<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use function array_values;
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
     * Walks the entire list even after a match, so the duration of a
     * successful verify does not leak the matched index. `hash_equals`
     * is constant-time per pair only; returning early would reintroduce
     * the leak at the list level, since a 5th-position match would
     * complete faster than a 200th-position one and each timing
     * observation would yield roughly log2(N) bits. Do not add a
     * `break` to the loop below.
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

    /**
     * Verify-and-consume in one call, and the API to prefer over
     * {@see verify()}. A recovery code is single-use: `verify()` alone
     * reports a matched index and leaves removal to the caller, so a
     * caller that forgets turns every match into a permanent backdoor,
     * the same code staying valid forever.
     *
     * This signature makes that mistake hard: a hit returns a
     * `RecoveryCodeConsumeResult` whose `remainingCodes` MUST be
     * persisted, a miss returns null. The matched code cannot be read
     * without also receiving the new state to commit.
     *
     * @param list<string> $validCodes Codes currently stored for the user
     *
     * @return RecoveryCodeConsumeResult|null Match data + remaining codes, or null on miss
     */
    public function verifyAndConsume(string $code, array $validCodes): ?RecoveryCodeConsumeResult
    {
        $matchedIndex = $this->verify($code, $validCodes);

        if ($matchedIndex === -1) {
            return null;
        }

        $remaining = $validCodes;
        unset($remaining[$matchedIndex]);

        return new RecoveryCodeConsumeResult(
            matchedIndex: $matchedIndex,
            remainingCodes: array_values($remaining),
        );
    }
}
