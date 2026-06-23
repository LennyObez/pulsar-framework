<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Service;

use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Domain\ReferrerSource;
use Pulsar\Support\Coerce;

use function parse_str;
use function parse_url;
use function preg_replace;
use function strtolower;
use function trim;

/**
 * Parses referrer URLs into classified traffic sources.
 *
 * Classifies referrers as direct, organic search, social media, UTM-tagged,
 * or generic referral based on domain and query parameter analysis.
 */
#[Internal(reason: 'Referrer parsing internals; use via service binding')]
final readonly class ReferrerParser
{
    /**
     * Search engine domain fragments → display names.
     *
     * @var array<string, string>
     */
    private const array SEARCH_ENGINES = [
        'google' => 'Google',
        'bing' => 'Bing',
        'yahoo' => 'Yahoo',
        'duckduckgo' => 'DuckDuckGo',
        'baidu' => 'Baidu',
        'yandex' => 'Yandex',
    ];

    /**
     * Social network domain fragments → display names.
     *
     * @var array<string, string>
     */
    private const array SOCIAL_NETWORKS = [
        'facebook' => 'Facebook',
        'twitter' => 'Twitter',
        'linkedin' => 'LinkedIn',
        'reddit' => 'Reddit',
        'youtube' => 'YouTube',
        'pinterest' => 'Pinterest',
        'instagram' => 'Instagram',
    ];

    /**
     * Social network exact domain matches → display names.
     *
     * Used for short domains that would cause false positives with substring matching.
     *
     * @var array<string, string>
     */
    private const array SOCIAL_EXACT_DOMAINS = [
        't.co' => 'Twitter',
        'x.com' => 'Twitter',
    ];

    /**
     * Parse a referrer URL and classify its traffic source.
     */
    public function parse(string $referrerUrl, string $currentDomain): ReferrerSource
    {
        $referrerUrl = trim($referrerUrl);

        // Empty referrer → direct traffic
        if ($referrerUrl === '') {
            return ReferrerSource::direct();
        }

        $parsed = parse_url($referrerUrl);
        if ($parsed === false || !isset($parsed['host'])) {
            return ReferrerSource::direct();
        }

        $host = strtolower($parsed['host']);

        // Strip www. prefix for comparison
        $normalizedHost = preg_replace('/^www\./', '', $host) ?? $host;
        $normalizedCurrent = preg_replace('/^www\./', '', strtolower(trim($currentDomain))) ?? strtolower(trim($currentDomain));

        // Same domain → direct (internal navigation)
        if ($normalizedHost === $normalizedCurrent) {
            return ReferrerSource::direct();
        }

        // Check for UTM parameters first (explicit campaign tagging takes priority)
        $queryString = $parsed['query'] ?? '';
        if ($queryString !== '') {
            parse_str($queryString, $queryParams);
            if (isset($queryParams['utm_source']) && $queryParams['utm_source'] !== '') {
                return ReferrerSource::fromUtm(
                    source: Coerce::string($queryParams['utm_source']),
                    medium: Coerce::string($queryParams['utm_medium'] ?? null),
                    campaign: Coerce::string($queryParams['utm_campaign'] ?? null),
                    rawUrl: $referrerUrl,
                );
            }
        }

        // Check search engines
        foreach (self::SEARCH_ENGINES as $fragment => $name) {
            if (str_contains($normalizedHost, $fragment)) {
                return ReferrerSource::fromOrganic($name, $referrerUrl);
            }
        }

        // Check social networks: exact domain matches first (short domains like t.co)
        if (isset(self::SOCIAL_EXACT_DOMAINS[$normalizedHost])) {
            return ReferrerSource::fromSocial(self::SOCIAL_EXACT_DOMAINS[$normalizedHost], $referrerUrl);
        }

        foreach (self::SOCIAL_NETWORKS as $fragment => $name) {
            if (str_contains($normalizedHost, $fragment)) {
                return ReferrerSource::fromSocial($name, $referrerUrl);
            }
        }

        // Unknown domain → generic referral
        return ReferrerSource::fromReferral($normalizedHost, $referrerUrl);
    }
}
