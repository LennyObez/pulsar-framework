<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\AiCrawler;

use Pulsar\Api\Api;

/**
 * Action the AI-crawler middleware applies to a detected crawler.
 * @api
 */
#[Api(since: '1.0.0')]
enum AiCrawlerAction: string
{
    /** Serve the request normally (the TDM-reservation header is still added). */
    case Allow = 'allow';

    /** Reject the request with 403 Forbidden. */
    case Block = 'block';

    /** Apply a strict per-crawler rate limit; throttle with 429 when exceeded. */
    case RateLimit = 'rate_limit';

    public static function fromString(string $value, self $default): self
    {
        return self::tryFrom($value) ?? $default;
    }
}
