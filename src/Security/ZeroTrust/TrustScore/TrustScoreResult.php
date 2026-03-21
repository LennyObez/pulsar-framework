<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\TrustScore;

use Pulsar\Api\Api;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;

/**
 * Computed trust score with full breakdown.
 *
 * The trust score is a normalized value (0.0-1.0) derived from the weighted
 * contributions of all claims in the set. The explanation array provides
 * per-claim breakdowns for debugging and audit purposes.
 */
#[Api(since: '1.0.0')]
final readonly class TrustScoreResult
{
    /**
     * @param float $score Normalized trust score (0.0 = no trust, 1.0 = full trust)
     * @param list<ScoreExplanation> $explanations Per-claim contribution breakdown
     * @param ClaimSet $claimSnapshot Claims used to compute the score
     */
    public function __construct(
        public float $score,
        public array $explanations,
        public ClaimSet $claimSnapshot,
    ) {}
}
