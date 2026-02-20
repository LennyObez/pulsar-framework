<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\AntiAbuse;

use Pulsar\Api\Internal;

use function mb_strlen;
use function mb_substr;
use function preg_match_all;
use function similar_text;

/**
 * Forum anti-abuse checks for post submission.
 *
 * Provides three independent checks that can be composed into request
 * validation pipelines:
 *
 * 1. Link density — rejects posts that are predominantly URLs (spam indicator)
 * 2. Similarity detection — prevents duplicate/near-duplicate posts
 * 3. Honeypot field — catches automated bot submissions
 */
#[Internal(reason: 'Anti-abuse internals — not part of public API')]
final readonly class ForumAntiAbuseMiddleware
{
    /**
     * @param float $maxLinkDensity Maximum ratio of link characters to total characters (0.0–1.0)
     * @param float $similarityThreshold Minimum similarity percentage to flag as duplicate (0.0–100.0)
     */
    public function __construct(
        private float $maxLinkDensity = 0.3,
        private float $similarityThreshold = 85.0,
    ) {}

    /**
     * Check whether the body exceeds the link density threshold.
     *
     * Calculates the ratio of characters inside URLs to the total body length.
     * A ratio above the threshold indicates likely spam.
     *
     * @param string $body Post body text (plain text or Markdown)
     * @return bool True if the link density is within acceptable limits
     */
    public function passesLinkDensityCheck(string $body): bool
    {
        $bodyLength = mb_strlen($body);

        if ($bodyLength === 0) {
            return true;
        }

        // Match URLs: http(s)://... and markdown links [text](url)
        $urlCharCount = 0;

        // Match raw URLs
        if (preg_match_all('#https?://[^\s\)>\]]+#i', $body, $matches)) {
            foreach ($matches[0] as $url) {
                $urlCharCount += mb_strlen($url);
            }
        }

        $density = $urlCharCount / $bodyLength;

        return $density <= $this->maxLinkDensity;
    }

    /**
     * Check whether the new body is too similar to a recent post.
     *
     * Uses PHP's similar_text() to compute a percentage similarity.
     * Returns false if the similarity exceeds the threshold (likely duplicate).
     *
     * @param string $newBody The body being submitted
     * @param string $recentBody A recent post body to compare against
     * @return bool True if the posts are sufficiently different
     */
    public function passesSimilarityCheck(string $newBody, string $recentBody): bool
    {
        if ($newBody === '' || $recentBody === '') {
            return true;
        }

        $newNormalized = mb_substr($this->normalizeForComparison($newBody), 0, 500);
        $recentNormalized = mb_substr($this->normalizeForComparison($recentBody), 0, 500);

        similar_text($newNormalized, $recentNormalized, $percent);

        return $percent < $this->similarityThreshold;
    }

    /**
     * Check whether the honeypot field is empty.
     *
     * A honeypot field is a hidden form input that legitimate users never fill in.
     * Bots that auto-fill all fields will populate it, revealing themselves.
     *
     * @param string $honeypotValue The value from the hidden honeypot field
     * @return bool True if the honeypot is empty (likely human)
     */
    public function passesHoneypotCheck(string $honeypotValue): bool
    {
        return $honeypotValue === '';
    }

    /**
     * Run all checks and return a list of failed check names.
     *
     * @param string $body The post body being submitted
     * @param string $honeypotValue The honeypot field value
     * @param list<string> $recentBodies Recent post bodies by the same user
     * @return list<string> Names of failed checks (empty = all passed)
     */
    public function validate(
        string $body,
        string $honeypotValue,
        array $recentBodies = [],
    ): array {
        $failures = [];

        if (!$this->passesHoneypotCheck($honeypotValue)) {
            $failures[] = 'honeypot';
        }

        if (!$this->passesLinkDensityCheck($body)) {
            $failures[] = 'link_density';
        }

        foreach ($recentBodies as $recentBody) {
            if (!$this->passesSimilarityCheck($body, $recentBody)) {
                $failures[] = 'similarity';

                break;
            }
        }

        return $failures;
    }

    /**
     * Normalize text for similarity comparison — lowercase, collapse whitespace.
     */
    private function normalizeForComparison(string $text): string
    {
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }
}
