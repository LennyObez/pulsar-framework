<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Contracts;

use Pulsar\Api\Api;

/**
 * Detects whether a request is from a known bot.
 * @api
 */
#[Api(since: '1.0.0')]
interface BotDetectorInterface
{
    /**
     * Determine whether the given user agent and headers indicate a bot.
     *
     * @param string $userAgent The HTTP User-Agent header value
     * @param array<string, string> $headers Normalized HTTP headers (lowercase keys)
     */
    public function isBot(string $userAgent, array $headers = []): bool;
}
