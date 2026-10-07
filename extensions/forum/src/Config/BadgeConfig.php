<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Config;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Badge system configuration with trigger thresholds.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BadgeConfig
{
    /**
     * @param bool $enabled Whether the badge system is enabled
     * @param int $helpfulUpvoteThreshold Upvotes needed on answers for Helpful badge
     * @param int $popularThreadViewThreshold Views needed on a thread for PopularThread badge
     * @param int $solverAcceptedAnswerThreshold Accepted answers needed for Solver badge
     * @param int $bugHunterConfirmedThreshold Confirmed bug reports needed for BugHunter badge
     * @param int $multilingualLocaleThreshold Distinct locales posted in for Multilingual badge
     */
    public function __construct(
        public bool $enabled = true,
        public int $helpfulUpvoteThreshold = 10,
        public int $popularThreadViewThreshold = 50,
        public int $solverAcceptedAnswerThreshold = 10,
        public int $bugHunterConfirmedThreshold = 5,
        public int $multilingualLocaleThreshold = 2,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            helpfulUpvoteThreshold: Coerce::int($data['helpful_upvote_threshold'] ?? null, 10),
            popularThreadViewThreshold: Coerce::int($data['popular_thread_view_threshold'] ?? null, 50),
            solverAcceptedAnswerThreshold: Coerce::int($data['solver_accepted_answer_threshold'] ?? null, 10),
            bugHunterConfirmedThreshold: Coerce::int($data['bug_hunter_confirmed_threshold'] ?? null, 5),
            multilingualLocaleThreshold: Coerce::int($data['multilingual_locale_threshold'] ?? null, 2),
        );
    }
}
