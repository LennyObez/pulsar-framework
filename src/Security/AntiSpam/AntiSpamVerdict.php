<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use NoDiscard;
use Pulsar\Api\Api;

use function in_array;

/**
 * A single, ready-to-act verdict distilled from an {@see AntiSpamResult} under a
 * {@see AntiSpamVerdictPolicy}.
 *
 * Controllers read ONE verdict instead of re-deriving "which checks hard-reject
 * vs only score", the captcha-when-token-present nuance, and the "content signals
 * never block, only flag" rule — so forms cannot drift apart in how they act on
 * the pipeline. `shouldReject()` blocks a submission; a delivered submission with
 * `flagged` set should be routed to human review.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AntiSpamVerdict
{
    /**
     * @param bool        $hardFailed   A hard-gate check failed — reject the submission
     * @param string|null $hardCheck    Name of the first failed hard gate, or null
     * @param int         $contentScore Accumulated score from content scorers only
     * @param bool        $flagged      Not hard-failed, but content score reached the review threshold
     */
    public function __construct(
        public bool $hardFailed,
        public ?string $hardCheck,
        public int $contentScore,
        public bool $flagged,
    ) {}

    #[NoDiscard]
    public static function from(AntiSpamResult $result, AntiSpamVerdictPolicy $policy): self
    {
        $hardFailed = false;
        $hardCheck = null;
        $contentScore = 0;

        foreach ($result->checkResults as $checkResult) {
            if (in_array($checkResult->checkName, $policy->hardGates, true)) {
                if (!$checkResult->passed && !$hardFailed) {
                    $hardFailed = true;
                    $hardCheck = $checkResult->checkName;
                }
            } elseif (in_array($checkResult->checkName, $policy->contentScorers, true)) {
                $contentScore += $checkResult->score;
            }
            // A check in neither set is intentionally ignored (e.g. captcha for a
            // client that never received a challenge token).
        }

        return new self(
            hardFailed: $hardFailed,
            hardCheck: $hardCheck,
            contentScore: $contentScore,
            flagged: !$hardFailed && $contentScore >= $policy->reviewScoreThreshold,
        );
    }

    /**
     * Whether the submission must be rejected outright.
     */
    #[NoDiscard]
    public function shouldReject(): bool
    {
        return $this->hardFailed;
    }
}
