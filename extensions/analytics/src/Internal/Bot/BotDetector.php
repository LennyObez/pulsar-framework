<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Bot;

use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;

/**
 * Multi-tier bot detection using user-agent patterns and header heuristics.
 *
 * Tier 1: Regex match against known bot UA patterns.
 * Tier 2: Header heuristics (missing Accept-Language often indicates a bot).
 */
#[Internal(reason: 'Bot detection internals — use via service binding')]
final readonly class BotDetector
{
    private string $combinedPattern;

    public function __construct(
        private AnalyticsConfig $config,
    ) {
        $this->combinedPattern = '/(' . implode('|', BotPatterns::PATTERNS) . ')/i';
    }

    /**
     * Determine whether the given user agent and headers indicate a bot.
     *
     * @param string $userAgent The HTTP User-Agent header value
     * @param array<string, string> $headers Normalized HTTP headers (lowercase keys)
     */
    public function isBot(string $userAgent, array $headers = []): bool
    {
        // Empty user agent is always a bot
        if ($userAgent === '') {
            return true;
        }

        // Tier 1: Known bot UA pattern matching
        if (preg_match($this->combinedPattern, $userAgent) === 1) {
            return true;
        }

        // Tier 2: Header heuristics — missing Accept-Language is a strong bot signal
        if (!isset($headers['accept-language']) || $headers['accept-language'] === '') {
            return true;
        }

        return false;
    }
}
