<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\AiCrawler\Internal;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerVerificationConfig;
use Pulsar\Security\AntiSpam\AiCrawler\CrawlerIdentity;

use function array_any;
use function hash;
use function in_array;
use function is_string;
use function max;
use function str_ends_with;

/**
 * Verifies that a request claiming to be a known crawler really comes from that
 * crawler's operator, defeating User-Agent spoofing.
 *
 * Two methods, tried in order:
 *  1. CIDR — the client IP must fall in the crawler's published ranges.
 *  2. FCrDNS — the IP's PTR host must end in an expected suffix and forward-
 *     resolve back to the same IP (the method search engines recommend).
 *
 * A crawler with no configured ranges or domains is {@see CrawlerIdentity::Unverifiable}
 * (we cannot judge it); one that is verifiable but matches nothing is an
 * {@see CrawlerIdentity::Impersonator}. FCrDNS results are cached to keep the
 * blocking lookups off the hot path.
 */
#[Internal]
final readonly class CrawlerIdentityVerifier
{
    public function __construct(
        private AiCrawlerVerificationConfig $config,
        private CrawlerDnsResolver $dns,
        private ?TaggedCacheInterface $cache = null,
    ) {}

    public function verify(string $crawlerToken, string $clientIp): CrawlerIdentity
    {
        if (!$this->config->enabled) {
            return CrawlerIdentity::Unverifiable;
        }

        $ranges = $this->config->rangesFor($crawlerToken);
        $domains = $this->config->domainsFor($crawlerToken);

        $cidrApplicable = $ranges !== [];
        $dnsApplicable = $this->config->reverseDns && $domains !== [];

        if (!$cidrApplicable && !$dnsApplicable) {
            return CrawlerIdentity::Unverifiable;
        }

        if ($cidrApplicable && CidrMatcher::matchesAny($clientIp, $ranges)) {
            return CrawlerIdentity::Verified;
        }

        if ($dnsApplicable && $this->forwardConfirmedReverseDns($clientIp, $domains)) {
            return CrawlerIdentity::Verified;
        }

        return CrawlerIdentity::Impersonator;
    }

    /**
     * @param list<string> $domains
     */
    private function forwardConfirmedReverseDns(string $clientIp, array $domains): bool
    {
        $cacheKey = 'ai-crawler:fcrdns:' . hash('sha256', $clientIp);

        /** @var mixed $cached */
        $cached = $this->cache?->get($cacheKey);
        if (is_string($cached)) {
            return $cached === '1';
        }

        $confirmed = $this->resolveForwardConfirmed($clientIp, $domains);

        $this->cache?->set($cacheKey, $confirmed ? '1' : '0', ['ai-crawler-fcrdns'], max(1, $this->config->cacheTtlSeconds));

        return $confirmed;
    }

    /**
     * @param list<string> $domains
     */
    private function resolveForwardConfirmed(string $clientIp, array $domains): bool
    {
        foreach ($this->dns->reverse($clientIp) as $host) {
            if (!$this->hostMatchesAnySuffix($host, $domains)) {
                continue;
            }

            if (in_array($clientIp, $this->dns->forward($host), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $domains
     */
    private function hostMatchesAnySuffix(string $host, array $domains): bool
    {
        return array_any(
            $domains,
            static fn(string $suffix): bool => $host === $suffix || str_ends_with($host, '.' . $suffix),
        );
    }
}
