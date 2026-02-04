<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Api;

/**
 * Optional rate limiting for 2FA verification attempts.
 *
 * Not wired by default — apps provide their own implementation
 * scoped to their specific rate limiting needs (identity + IP + timeframe, etc.).
 *
 * Documented context keys (apps decide which to populate):
 * - 'ip': Client IP address
 * - 'user_agent_hash': Hashed user agent string
 * - 'device_id': Persistent device fingerprint
 * - 'route': Route name for step-up context
 * - 'session_id': Hashed session ID
 */
#[Api(since: '1.0.0')]
interface TwoFactorRateLimiterInterface
{
    /**
     * Check if a verification attempt is allowed.
     *
     * @param string $identityId Identity attempting verification
     * @param TwoFactorPurpose $purpose The verification purpose
     * @param array<string, string> $context Additional context for scoping
     *
     * @return bool true if allowed, false if rate limited
     */
    public function attempt(string $identityId, TwoFactorPurpose $purpose, array $context = []): bool;

    /**
     * Reset rate limit counters for an identity.
     */
    public function reset(string $identityId): void;
}
