<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Behavior;

use Pulsar\Api\Api;

/**
 * Grades behavioural signals into a spam score contribution.
 *
 * Implementations return a non-negative score: 0 means "looks human / no
 * signal", higher means "more bot-like". The check that uses a scorer is
 * SCORE-ONLY — it never hard-rejects — so a scorer must never assume its
 * verdict is final. Ship {@see HeuristicScorer} by default; an application can
 * bind its own implementation (e.g. a trained model) without touching the
 * framework.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
interface BehaviorScorerInterface
{
    /**
     * @return int Non-negative spam score contribution (0 = clean).
     */
    public function score(BehaviorSignals $signals): int;
}
