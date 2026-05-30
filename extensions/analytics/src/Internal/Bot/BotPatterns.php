<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Bot;

use Pulsar\Api\Internal;

/**
 * Known bot user-agent regex patterns for bot detection.
 */
#[Internal(reason: 'Bot detection internals; use BotDetector')]
final class BotPatterns
{
    /**
     * Regex patterns matching known bot/crawler user agents.
     *
     * Each pattern is a case-insensitive partial match against the full UA string.
     *
     * @var list<non-empty-string>
     */
    public const array PATTERNS = [
        'googlebot',
        'bingbot',
        'yandex',
        'baidu',
        'duckduckbot',
        'facebot',
        'twitterbot',
        'linkedinbot',
        'slurp',
        'msnbot',
        'crawl',
        'spider',
        'bot\b',
        'archive',
        'wget',
        'curl',
        'python-requests',
        'go-http-client',
        '\bjava\b',
        'httpclient',
    ];

}
