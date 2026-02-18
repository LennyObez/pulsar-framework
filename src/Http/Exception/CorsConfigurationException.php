<?php

declare(strict_types=1);

namespace Pulsar\Http\Exception;

use LogicException;
use Pulsar\Api\Api;

/**
 * Thrown when an unsafe CORS configuration is detected at boot time.
 *
 * The CORS spec forbids combining the wildcard `*` origin with
 * `Access-Control-Allow-Credentials: true` because the wildcard
 * defeats the per-origin scoping that credentials require. Catching
 * the misconfiguration in the constructor surfaces it before any
 * request can be served.
 */
#[Api(since: '1.0.0')]
final class CorsConfigurationException extends LogicException
{
    public static function credentialsWithWildcard(): self
    {
        return new self(
            'CORS configuration is unsafe: allow_credentials cannot be true when allowed_origins includes the wildcard "*". '
            . 'Replace the wildcard with an explicit allowlist of trusted origins, or disable allow_credentials.',
        );
    }
}
