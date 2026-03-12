<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Api;

/**
 * Result of {@see RecoveryCodeVerifier::verifyAndConsume()}.
 *
 * F12.4: forces the caller to commit to consumption. Reading
 * `matchedIndex` without persisting `remainingCodes` leaves the
 * caller stuck with the same backdoor (every recovery code stays
 * valid forever) — the verify-and-consume contract therefore
 * exposes both at once so a code review of the consume path
 * trivially detects "matched but never persisted" mistakes.
 */
#[Api(since: '1.0.0')]
final readonly class RecoveryCodeConsumeResult
{
    /**
     * @param int $matchedIndex Index of the matched code in the original list
     * @param list<string> $remainingCodes Codes that survive after consumption
     */
    public function __construct(
        public int $matchedIndex,
        public array $remainingCodes,
    ) {}
}
