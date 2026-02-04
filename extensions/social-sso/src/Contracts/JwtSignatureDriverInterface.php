<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\SocialSso\Domain\JwkKey;

/**
 * Pluggable JWT signature verification driver.
 *
 * Implementations provide algorithm-specific signature verification (e.g. RS256, ES256).
 */
#[Api(since: '1.0.0')]
interface JwtSignatureDriverInterface
{
    /**
     * Verify a JWT signature against the given key.
     *
     * @param string $header   Base64url-decoded JWT header
     * @param string $payload  Base64url-decoded JWT payload
     * @param string $signature Base64url-decoded JWT signature
     */
    public function verify(string $header, string $payload, string $signature, JwkKey $key): bool;

    /**
     * Check whether this driver supports the given algorithm.
     */
    public function supports(string $algorithm): bool;
}
