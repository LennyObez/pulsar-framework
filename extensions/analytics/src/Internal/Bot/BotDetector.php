<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Bot;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\BotDetectorInterface;

use function implode;
use function preg_match;

/**
 * Multi-tier bot detection using user-agent patterns and header heuristics.
 *
 * Tier 1: Empty user agent is always flagged as bot.
 * Tier 2: Regex match against known bot UA patterns.
 * Tier 3: Combined heuristic requiring BOTH a suspicious user agent (no
 *          recognized browser token) AND missing/empty Accept-Language.
 *          This avoids false positives for API clients and SPA frontends
 *          that legitimately omit Accept-Language.
 *
 * When `$strictMode` is enabled, missing Accept-Language alone is sufficient
 * to flag a request as bot (original behavior). Default is non-strict.
 */
#[Internal(reason: 'Bot detection internals; use via service binding')]
final readonly class BotDetector implements BotDetectorInterface
{
    private string $combinedPattern;

    /**
     * Regex matching common browser tokens present in legitimate user agents.
     * Used in Tier 3 to distinguish headless/automated UAs from real browsers.
     */
    private const string BROWSER_PATTERN = '/(?:Chrome|Firefox|Safari|Edge|Opera|MSIE|Trident)/i';

    /**
     * @param bool $strictMode When true, missing Accept-Language alone flags as bot.
     *                         When false (default), missing Accept-Language only flags
     *                         as bot when the UA also lacks recognized browser tokens.
     */
    public function __construct(
        private bool $strictMode = false,
    ) {
        $this->combinedPattern = '/(' . implode('|', BotPatterns::PATTERNS) . ')/i';
    }

    /**
     * Determine whether the given user agent and headers indicate a bot.
     *
     * @param string $userAgent The HTTP User-Agent header value
     * @param array<string, string> $headers Normalized HTTP headers (lowercase keys)
     */
    #[Override]
    public function isBot(string $userAgent, array $headers = []): bool
    {
        // Tier 1: Empty user agent is always a bot
        if ($userAgent === '') {
            return true;
        }

        // Tier 2: Known bot UA pattern matching
        if (preg_match($this->combinedPattern, $userAgent) === 1) {
            return true;
        }

        // Tier 3: Header heuristic for Accept-Language
        $missingAcceptLanguage = !isset($headers['accept-language']) || $headers['accept-language'] === '';

        if (!$missingAcceptLanguage) {
            return false;
        }

        // In strict mode, missing Accept-Language alone is sufficient
        if ($this->strictMode) {
            return true;
        }

        // In default mode, require BOTH missing Accept-Language AND a
        // non-browser UA (no recognized browser token). This prevents
        // false positives for API clients and SPA frontends.
        return preg_match(self::BROWSER_PATTERN, $userAgent) !== 1;
    }
}
