<?php

declare(strict_types=1);

namespace Pulsar\Http\RateLimit;

use Pulsar\Api\Api;

/**
 * How {@see \Pulsar\Http\Middleware\RateLimitMiddleware} derives the bucket key
 * for a request.
 *
 * - `Ip`: per client (IP, plus the authenticated user id when present). The
 *   default — throttles each caller independently.
 * - `Route`: per matched route, shared across all clients. Caps the total load a
 *   single endpoint can take (e.g. an expensive search or export).
 * - `IpAndRoute`: per client per route — a caller's budget on one endpoint does
 *   not spend their budget on another.
 * @api
 */
#[Api(since: '1.0.0')]
enum RateLimitKeyStrategy: string
{
    case Ip = 'ip';
    case Route = 'route';
    case IpAndRoute = 'ip_route';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? self::Ip;
    }
}
