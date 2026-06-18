<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Api;

/**
 * Marker for a 2FA rate limiter that performs no enforcement.
 *
 * A {@see TwoFactorRateLimiterInterface} implementing this marker explicitly
 * disables rate limiting (e.g. {@see AllowAllTwoFactorRateLimiter}, a dev/test
 * placeholder). Deploy-time readiness checks reject such a limiter in staging
 * and production by testing against this stable #[Api] marker, rather than
 * depending on a concrete, module-internal implementation across a module
 * boundary.
 * @api
 */
#[Api(since: '1.0.0')]
interface NonEnforcingTwoFactorRateLimiterInterface
{
}
