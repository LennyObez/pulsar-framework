<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\AiCrawler;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function array_filter;
use function array_values;
use function is_array;
use function is_string;

/**
 * Configuration for verifying the identity of declared AI crawlers.
 *
 * UA-based detection alone is forgeable: a scraper can claim to be an allowed
 * crawler to slip past. When enabled, a detected crawler's client IP is checked
 * against the operator-supplied published ranges ($ranges, keyed by UA token)
 * and/or forward-confirmed reverse DNS ($domains, when $reverseDns is on). A
 * crawler that is verifiable but matches nothing is treated as an impersonator
 * and blocked, regardless of its configured action.
 *
 * Ranges and domains are operator-supplied (issuers publish them, e.g.
 * OpenAI's gptbot.json) rather than baked in, since they change over time.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AiCrawlerVerificationConfig
{
    /**
     * @param array<string, list<string>> $ranges  UA token => published IP/CIDR ranges
     * @param array<string, list<string>> $domains UA token => expected reverse-DNS host suffixes
     */
    public function __construct(
        public bool $enabled = false,
        public bool $reverseDns = false,
        public array $ranges = [],
        public array $domains = [],
        public int $cacheTtlSeconds = 3600,
    ) {}

    /**
     * @return list<string>
     */
    #[NoDiscard]
    public function rangesFor(string $crawlerToken): array
    {
        return $this->ranges[$crawlerToken] ?? [];
    }

    /**
     * @return list<string>
     */
    #[NoDiscard]
    public function domainsFor(string $crawlerToken): array
    {
        return $this->domains[$crawlerToken] ?? [];
    }

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     reverse_dns?: bool|int|string,
     *     ranges?: array<string, list<string>>,
     *     domains?: array<string, list<string>>,
     *     cache_ttl_seconds?: int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $ranges = $data['ranges'] ?? null;
        $domains = $data['domains'] ?? null;

        return new self(
            enabled: Coerce::strictBool($data['enabled'] ?? null),
            reverseDns: Coerce::strictBool($data['reverse_dns'] ?? null),
            ranges: is_array($ranges) ? self::normalizeMap($ranges) : [],
            domains: is_array($domains) ? self::normalizeMap($domains) : [],
            cacheTtlSeconds: Coerce::int($data['cache_ttl_seconds'] ?? null, 3600),
        );
    }

    /**
     * @param array<array-key, mixed> $map
     *
     * @return array<string, list<string>>
     */
    private static function normalizeMap(array $map): array
    {
        $result = [];

        /** @psalm-suppress MixedAssignment Raw config map values are inherently mixed; validated by is_array/is_string before use. */
        foreach ($map as $token => $values) {
            if (is_string($token) && is_array($values)) {
                /** @var list<string> $strings */
                $strings = array_values(array_filter($values, is_string(...)));
                $result[$token] = $strings;
            }
        }

        return $result;
    }
}
