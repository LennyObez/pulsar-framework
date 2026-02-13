<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Service;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Report\PostReportRepositoryInterface;
use Pulsar\Extension\Forum\Report\ThreadReportRepositoryInterface;

use function preg_match_all;
use function similar_text;

/**
 * Auto-moderation rules engine for forum content.
 *
 * Evaluates incoming posts and threads against configurable heuristics
 * to flag suspicious content, rate-limit new users, and detect abuse
 * patterns before they reach human moderators.
 */
#[Internal(reason: 'Internal auto-moderation engine — not part of public API')]
final readonly class AutoModerationService
{
    /** Reputation threshold below which posts are queued for review. */
    private const int REVIEW_REPUTATION_THRESHOLD = 10;

    /** Number of unique flags required to auto-hide content. */
    private const int AUTO_HIDE_FLAG_THRESHOLD = 3;

    /** Reputation threshold below which URL limits are enforced. */
    private const int URL_LIMIT_REPUTATION_THRESHOLD = 50;

    /** Maximum URLs allowed for low-reputation users. */
    private const int MAX_URLS_FOR_NEW_USERS = 2;

    /** Minimum similarity percentage to consider a post duplicate. */
    private const int DUPLICATE_SIMILARITY_THRESHOLD = 85;

    /** Minimum seconds between posts for low-reputation users. */
    private const int MIN_POST_INTERVAL_SECONDS = 300;

    /** Reputation threshold below which rate limiting is enforced. */
    private const int RATE_LIMIT_REPUTATION_THRESHOLD = 10;

    public function __construct(
        private PostRepositoryInterface $posts,
        private ThreadReportRepositoryInterface $threadReports,
        private PostReportRepositoryInterface $postReports,
    ) {}

    /**
     * Whether a post by this author should be queued for manual review.
     *
     * New users with a reputation score below the review threshold have
     * their posts held for moderator approval before becoming visible.
     */
    public function shouldQueueForReview(ForumProfile $author): bool
    {
        return $author->reputationScore < self::REVIEW_REPUTATION_THRESHOLD;
    }

    /**
     * Whether content should be auto-hidden based on report volume.
     *
     * Content that accumulates reports from 3 or more unique reporters
     * is automatically hidden pending moderator review.
     */
    public function shouldAutoHide(string $targetType, string $targetId): bool
    {
        $reportCount = match ($targetType) {
            'thread' => $this->threadReports->countPendingForThread($targetId),
            'post' => $this->postReports->countPendingForPost($targetId),
            default => 0,
        };

        return $reportCount >= self::AUTO_HIDE_FLAG_THRESHOLD;
    }

    /**
     * Whether a post body exceeds the URL limit for low-reputation users.
     *
     * Users with reputation below the URL threshold are limited to a
     * maximum number of URLs per post to prevent link spam.
     */
    public function isUrlLimitExceeded(ForumProfile $author, string $body): bool
    {
        if ($author->reputationScore >= self::URL_LIMIT_REPUTATION_THRESHOLD) {
            return false;
        }

        $urlCount = preg_match_all(
            '#https?://[^\s<>\[\]]+#i',
            $body,
        );

        return $urlCount > self::MAX_URLS_FOR_NEW_USERS;
    }

    /**
     * Whether the post body is a near-duplicate of a recent post by the same author.
     *
     * Compares the body against the author's posts created since the given timestamp
     * using similarity analysis. Posts with 85%+ similarity are flagged as duplicates.
     */
    public function isDuplicate(string $authorId, string $body, DateTimeImmutable $since): bool
    {
        $recentPosts = $this->posts->findByAuthor($authorId, 1, 10);

        return array_any($recentPosts->items, static function (object $post) use ($body, $since): bool {
            if ($post->createdAt < $since) {
                return false;
            }

            $similarity = 0.0;
            similar_text($body, $post->body, $similarity);

            return $similarity >= self::DUPLICATE_SIMILARITY_THRESHOLD;
        });
    }

    /**
     * Whether the author is posting too fast based on their reputation level.
     *
     * Low-reputation users (score below 10) must wait at least 5 minutes
     * between consecutive posts to prevent flooding.
     */
    public function isPostingTooFast(ForumProfile $author, DateTimeImmutable $lastPostAt): bool
    {
        if ($author->reputationScore >= self::RATE_LIMIT_REPUTATION_THRESHOLD) {
            return false;
        }

        $now = new DateTimeImmutable();
        $elapsed = $now->getTimestamp() - $lastPostAt->getTimestamp();

        return $elapsed < self::MIN_POST_INTERVAL_SECONDS;
    }
}
