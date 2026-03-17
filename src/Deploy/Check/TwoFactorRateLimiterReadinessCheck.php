<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter;
use Pulsar\Auth\TwoFactor\TwoFactorRateLimiterInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;

/**
 * Validates that a real two-factor rate limiter is wired in production.
 *
 * SEC-2FA-01: {@see TwoFactorManager} fails closed when no rate limiter is
 * present, but {@see AllowAllTwoFactorRateLimiter} is an explicit dev/test
 * placeholder that lets every attempt through. Deploying it to production
 * would silently disable 2FA brute-force protection, so the deploy gate
 * refuses it (and a missing binding) in staging/production environments.
 */
#[Internal]
final readonly class TwoFactorRateLimiterReadinessCheck implements DeployCheckInterface
{
    private const string CHECK_NAME = 'two-factor-rate-limiter';

    public function __construct(
        private ContainerInterface $container,
    ) {}

    #[Override]
    public function getName(): string
    {
        return self::CHECK_NAME;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Validates a production-grade 2FA rate limiter is wired';
    }

    #[Override]
    public function check(string $environment): CheckResult
    {
        if ($environment === 'local') {
            return CheckResult::pass(
                self::CHECK_NAME,
                'Rate limiter check is skipped in local environment',
            );
        }

        if (!$this->container->has(TwoFactorRateLimiterInterface::class)) {
            return CheckResult::error(
                self::CHECK_NAME,
                "No TwoFactorRateLimiterInterface binding registered for $environment",
                [
                    'Bind a real implementation (token bucket / leaky bucket / Redis-backed)',
                    'scoped to identity + IP + timeframe in your composition root.',
                ],
            );
        }

        $instance = $this->container->get(TwoFactorRateLimiterInterface::class);

        if ($instance instanceof AllowAllTwoFactorRateLimiter) {
            return CheckResult::error(
                self::CHECK_NAME,
                "AllowAllTwoFactorRateLimiter is wired in $environment",
                [
                    'AllowAllTwoFactorRateLimiter is a dev/test placeholder that disables rate limiting.',
                    'Replace it with a real implementation before deploying to staging or production.',
                ],
            );
        }

        return CheckResult::pass(
            self::CHECK_NAME,
            'TwoFactorRateLimiterInterface is wired to a non-placeholder implementation',
        );
    }
}
