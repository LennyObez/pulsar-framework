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

    /**
     * F12.4: verify-and-consume in one call. The previous
     * {@see verify()} returned a matched index without consuming
     * the code, leaving the caller responsible for removing the
     * matched entry from the user's stored list. A developer who
     * forgot to do that turned every match into a permanent
     * backdoor — the same code stays valid forever.
     *
     * The new API forces consumption: either the call returns a
     * `RecoveryCodeConsumeResult` whose `remainingCodes` MUST be
     * persisted, or the call returns null on miss. Callers cannot
     * read the matched code without committing to the new state.
     *
     * @param list<string> $validCodes Codes currently stored for the user
     *
     * @return RecoveryCodeConsumeResult|null Match data + remaining codes, or null on miss
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function verifyAndConsume(string $code, array $validCodes): ?RecoveryCodeConsumeResult
    {
        $matchedIndex = $this->verify($code, $validCodes);

        if ($matchedIndex === -1) {
            return null;
        }

        // Strip the matched code from the list.
        $remaining = $validCodes;
        unset($remaining[$matchedIndex]);

        return new RecoveryCodeConsumeResult(
            matchedIndex: $matchedIndex,
            remainingCodes: array_values($remaining),
        );
    }
}
