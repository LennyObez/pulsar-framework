<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\TrustScore\Internal;

use Pulsar\Api\Internal;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;
use Pulsar\Security\ZeroTrust\TrustScore\ScoreExplanation;
use Pulsar\Security\ZeroTrust\TrustScore\TrustScoreResult;

use function array_key_exists;
use function max;
use function min;

/**
 * Computes a trust score from a ClaimSet using configurable weights.
 *
 * The trust score is a diagnostic metric (0.0-1.0) intended for dashboards
 * and telemetry. It is NOT used for access decisions — the policy engine
 * handles authorization independently.
 *
 * Score computation: weighted average of present claims' confidence values.
 * Claims not present in the weight map are ignored. If no weighted claims
 * are present, the score is 0.0.
 */
#[Internal(reason: 'Diagnostic utility, not for access decisions')]
final readonly class TrustScoreCalculator
{
    /**
     * Compute a trust score from claims using the given weight map.
     *
     * @param ClaimSet $claims The claims to score
     * @param array<string, float> $weights Map of claim name to weight (positive increases, negative decreases)
     */
    public static function fromClaims(ClaimSet $claims, array $weights): TrustScoreResult
    {
        $explanations = [];
        $totalWeight = 0.0;
        $weightedSum = 0.0;

        foreach ($claims->all() as $claim) {
            if (!array_key_exists($claim->name, $weights)) {
                continue;
            }

            $weight = $weights[$claim->name];
            $contribution = $weight * $claim->confidence;
            $absWeight = $weight >= 0.0 ? $weight : -$weight;
            $totalWeight += $absWeight;
            $weightedSum += $contribution;

            $explanations[] = new ScoreExplanation(
                claimName: $claim->name,
                weight: $weight,
                claimConfidence: $claim->confidence,
                contribution: $contribution,
            );
        }

        $score = $totalWeight > 0.0 ? $weightedSum / $totalWeight : 0.0;
        $score = min(1.0, max(0.0, $score));

        return new TrustScoreResult(
            score: $score,
            explanations: $explanations,
            claimSnapshot: $claims,
        );
    }
}
