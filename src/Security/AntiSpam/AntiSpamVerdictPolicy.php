<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Declares how {@see AntiSpamVerdict} interprets a pipeline's per-check results:
 * which checks hard-reject, which only accumulate a review score, and the score
 * at which a delivered submission is flagged for review.
 *
 * A check named in neither set is ignored — this is how the "captcha is a hard
 * gate only when a token was actually sent" nuance is expressed: build the policy
 * with `captchaTokenPresent: false` for a no-JavaScript client and the captcha
 * result neither blocks nor scores, so a legitimate visitor is never penalised
 * for a challenge they were never served.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AntiSpamVerdictPolicy
{
    /**
     * @param list<string> $hardGates      Check names that hard-reject on failure
     * @param list<string> $contentScorers Check names that only accumulate a review score
     * @param int          $reviewScoreThreshold Accumulated content score at/above which a
     *     (non-hard-failed) submission is flagged for review but still delivered
     */
    public function __construct(
        public array $hardGates,
        public array $contentScorers,
        public int $reviewScoreThreshold = 50,
    ) {}

    /**
     * The framework's recommended policy: honeypot, time-trap and e-mail-domain
     * are hard gates; the content signals only score. Captcha is added to the
     * hard gates only when the client actually submitted a challenge token.
     */
    #[NoDiscard]
    public static function default(bool $captchaTokenPresent = false): self
    {
        $hardGates = ['honeypot', 'time_trap', 'email_domain'];

        if ($captchaTokenPresent) {
            $hardGates[] = 'captcha';
        }

        return new self(
            hardGates: $hardGates,
            contentScorers: ['content_quality', 'link_density', 'duplicate', 'reputation_cooldown', 'account_age'],
            reviewScoreThreshold: 50,
        );
    }
}
