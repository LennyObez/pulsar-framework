<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\TrustScore;

use Pulsar\Api\Api;

/**
 * Per-claim contribution to the overall trust score.
 *
 * Explains how a single claim affected the trust score, including
 * the weight assigned to that claim and its actual contribution.
 */
#[Api(since: '1.0.0')]
readonly class ScoreExplanation
{
    /**
     * @param string $claimName The claim that contributed to the score
     * @param float $weight Weight assigned to this claim type (0.0-1.0)
     * @param float $claimConfidence Original confidence of the claim
     * @param float $contribution Effective contribution: weight * claimConfidence
     */
    public function __construct(
        public string $claimName,
        public float $weight,
        public float $claimConfidence,
        public float $contribution,
    ) {}
}
