<?php

declare(strict_types=1);

namespace Pulsar\Security\PostureScore;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

use function max;

/**
 * Evaluates the application's security posture as a 0-100 score.
 *
 * Each registered check corresponds to a SecurityControl. When a control
 * is inactive, its weight is deducted from the maximum score (100).
 * The score is clamped to [0, 100].
 */
#[Api(since: '1.0.0')]
final class SecurityScorer
{
    /** @var list<PostureCheckInterface> */
    private array $checks = [];

    /**
     * Register a posture check.
     */
    public function addCheck(PostureCheckInterface $check): void
    {
        $this->checks[] = $check;
    }

    /**
     * Evaluate all registered checks and compute the posture score.
     */
    #[NoDiscard]
    public function evaluate(): PostureResult
    {
        $score = 100;
        $controls = [];
        $recommendations = [];

        foreach ($this->checks as $check) {
            $name = $check->control()->value;
            $active = $check->isActive();
            $controls[$name] = $active;

            if (!$active) {
                $score -= $check->control()->weight();
                $recommendations[$name] = $check->recommendation();
            }
        }

        return new PostureResult(
            score: max(0, $score),
            controls: $controls,
            recommendations: $recommendations,
            evaluatedAt: new DateTimeImmutable(),
        );
    }
}
