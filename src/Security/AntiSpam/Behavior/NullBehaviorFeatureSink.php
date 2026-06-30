<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Behavior;

use Override;
use Pulsar\Api\Internal;

/**
 * Default no-op feature sink: records nothing.
 *
 * Keeps behavioural scoring zero-retention by default — an application opts in
 * to training-data capture by binding its own {@see BehaviorFeatureSink}.
 */
#[Internal(reason: 'Use BehaviorFeatureSink')]
final readonly class NullBehaviorFeatureSink implements BehaviorFeatureSink
{
    #[Override]
    public function record(array $featureVector, int $score): void
    {
        // Intentionally empty: zero-retention default.
    }
}
