<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Api;

/**
 * Result of {@see RecoveryCodeVerifier::verifyAndConsume()}.
 *
 * Carries the match and the post-consumption state together so the
 * caller has to commit to both. Reading `matchedIndex` without
 * persisting `remainingCodes` keeps every recovery code valid
 * forever; pairing them makes a "matched but never persisted" bug
 * visible in review of the consume path.
 * @api
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
