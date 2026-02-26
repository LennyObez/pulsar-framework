<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Comments;

use Pulsar\Api\Api;
use Pulsar\Cache\Application\TaggedCacheInterface;

use function hash;
use function mb_strlen;
use function preg_match_all;
use function sprintf;

/**
 * Testable anti-abuse heuristics for comment submissions.
 *
 * Extracted from middleware to allow unit testing of each heuristic
 * independently from the HTTP layer.
 */
#[Api(since: '1.0.0')]
final readonly class AntiAbuseHeuristics
{
    /** Cache TTL for duplicate detection (5 minutes). */
    private const int DUPLICATE_WINDOW_SECONDS = 300;

    public function __construct(
        private TaggedCacheInterface $cache,
    ) {}

    /**
     * Check whether this exact body has been submitted recently from the same IP.
     */
    public function isDuplicate(string $bodyHash, string $ipHash): bool
    {
        $key = sprintf('cms_comment_dedup:%s:%s', $ipHash, $bodyHash);

        return $this->cache->get($key) !== null;
    }

    /**
     * Record a submission for future duplicate detection.
     */
    public function recordSubmission(string $bodyHash, string $ipHash): void
    {
        $key = sprintf('cms_comment_dedup:%s:%s', $ipHash, $bodyHash);

        $this->cache->set($key, '1', ['cms_comment_dedup'], self::DUPLICATE_WINDOW_SECONDS);
    }

    /**
     * Check whether the body contains too many links.
     */
    public function isLinkSpam(string $body, int $maxLinks = 3): bool
    {
        $count = preg_match_all('/<a\s|https?:\/\//i', $body);

        return $count > $maxLinks;
    }

    /**
     * Check whether the body exceeds the maximum allowed length.
     */
    public function isTooLong(string $body, int $maxLength = 10000): bool
    {
        return mb_strlen($body) > $maxLength;
    }

    /**
     * Check for excessive character repetition (e.g., "aaaaaaaaaa" or "!!!!!!!!").
     *
     * Detects any single character repeated 10+ times consecutively.
     */
    public function hasExcessiveRepetition(string $body): bool
    {
        return preg_match('/(.)\1{9,}/u', $body) === 1;
    }

    /**
     * Compute a BLAKE2b hash of the comment body for duplicate detection.
     */
    public function hashBody(string $body): string
    {
        return hash('xxh3', $body);
    }
}
