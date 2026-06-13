<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Behavior;

use Pulsar\Api\Api;

/**
 * Records labelled behavioural feature vectors for offline model training.
 *
 * Opt-in ML-readiness hook: an application binds an implementation to capture
 * `{feature_vector, outcome}` pairs (e.g. to a data warehouse) and later train
 * a model to plug in via {@see BehaviorScorerInterface}. The framework ships
 * {@see NullBehaviorFeatureSink} (a no-op) by default, so nothing is recorded
 * unless an application opts in. Implementations MUST NOT persist PII — the
 * vector is already non-identifying and must stay that way.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
interface BehaviorFeatureSink
{
    /**
     * @param array<string, bool|float|int> $featureVector The non-identifying signal vector
     * @param int                           $score         The score the scorer assigned
     */
    public function record(array $featureVector, int $score): void;
}
