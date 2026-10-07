<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;

use function bin2hex;
use function mb_strtolower;
use function mb_substr;
use function preg_replace;
use function similar_text;
use function sodium_crypto_generichash;
use function sprintf;
use function trim;

/**
 * Detects duplicate or near-duplicate submissions.
 *
 * Uses BLAKE2b hashing for exact duplicates and similar_text() for
 * near-duplicates. Tracks submissions per IP in the cache layer
 * with a configurable time window.
 */
#[Internal(reason: 'Use DuplicateDetectorInterface')]
final readonly class DuplicateDetector implements DuplicateDetectorInterface
{
    private const string CACHE_TAG = 'antispam_dedup';

    public function __construct(
        private TaggedCacheInterface $cache,
        private int $windowSeconds = 300,
        private float $similarityThreshold = 85.0,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'duplicate';
    }

    #[Override]
    public function check(AntiSpamContext $context): AntiSpamCheckResult
    {
        if ($context->body === '') {
            return AntiSpamCheckResult::pass($this->name());
        }

        $bodyHash = bin2hex(sodium_crypto_generichash($context->body));
        // Dot-separated: ':' is a PSR-6 reserved character the tagged cache
        // rejects. ipHash and bodyHash are hex digests, so they are key-safe.
        $cacheKey = sprintf('antispam_dedup.%s.%s', $context->ipHash, $bodyHash);

        // Exact duplicate check via cache
        if ($this->cache->get($cacheKey) !== null) {
            return AntiSpamCheckResult::fail(
                $this->name(),
                40,
                'Duplicate submission detected: same content was submitted recently',
            );
        }

        // Near-duplicate check against recent bodies
        $normalized = $this->normalize($context->body);

        foreach ($context->recentBodies as $recentBody) {
            $recentNormalized = $this->normalize($recentBody);
            similar_text($normalized, $recentNormalized, $percent);

            if ($percent >= $this->similarityThreshold) {
                return AntiSpamCheckResult::fail(
                    $this->name(),
                    35,
                    'Near-duplicate submission detected: content is too similar to a recent submission',
                );
            }
        }

        // Record this submission for future duplicate detection
        $this->cache->set($cacheKey, '1', [self::CACHE_TAG], $this->windowSeconds);

        return AntiSpamCheckResult::pass($this->name());
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return mb_substr($text, 0, 500);
    }
}
