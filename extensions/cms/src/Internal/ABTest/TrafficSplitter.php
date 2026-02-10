<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\ABTest;

use InvalidArgumentException;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\ABTest\Experiment;
use Pulsar\Extension\Cms\ABTest\ExperimentVariant;

use function array_sum;
use function array_values;
use function count;
use function hash;
use function hexdec;
use function substr;

/**
 * Deterministic traffic splitter for A/B test experiments.
 *
 * Uses hash(visitorId + experimentId) to consistently assign the same
 * visitor to the same variant across requests.
 */
#[Internal(reason: 'A/B test implementation detail')]
final readonly class TrafficSplitter
{
    /**
     * Select a variant for the given visitor using weighted deterministic hashing.
     *
     * @param list<ExperimentVariant> $variants
     */
    public function selectVariant(Experiment $experiment, array $variants, string $visitorId): ExperimentVariant
    {
        if ($variants === []) {
            throw new InvalidArgumentException('Cannot select variant from empty list');
        }

        if (count($variants) === 1) {
            return $variants[0];
        }

        $hash = hash('xxh3', $visitorId . $experiment->id);
        $hashInt = (int) hexdec(substr($hash, 0, 8));

        $totalWeight = array_sum(array_map(
            static fn(ExperimentVariant $v): int => $v->weight,
            $variants,
        ));

        if ($totalWeight <= 0) {
            return $variants[0];
        }

        $bucket = $hashInt % $totalWeight;
        $cumulative = 0;

        foreach ($variants as $variant) {
            $cumulative += $variant->weight;

            if ($bucket < $cumulative) {
                return $variant;
            }
        }

        // Fallback (should not reach here)
        return array_values($variants)[count($variants) - 1];
    }
}
