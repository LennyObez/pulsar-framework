<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use Pulsar\Api\Internal;
use Pulsar\Config\SecurityConfig;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;

use function sprintf;

/**
 * Validates that rate limiting is configured and active.
 *
 * Rate limiting protects against abuse, brute-force attacks, and resource
 * exhaustion. This check verifies the rate limiting feature is enabled.
 */
#[Internal]
final readonly class RateLimitCheck implements DeployCheckInterface
{
    private const string CHECK_NAME = 'rate-limiting';

    public function __construct(
        private SecurityConfig $securityConfig,
    ) {}

    public function getName(): string
    {
        return self::CHECK_NAME;
    }

    public function getDescription(): string
    {
        return 'Validates rate limiting is enabled for the target environment';
    }

    public function check(string $environment): CheckResult
    {
        if ($this->securityConfig->rateLimit->enabled) {
            return CheckResult::pass(
                self::CHECK_NAME,
                sprintf(
                    'Rate limiting is enabled (limit: %d requests per %d seconds)',
                    $this->securityConfig->rateLimit->defaultLimit,
                    $this->securityConfig->rateLimit->defaultWindow,
                ),
            );
        }

        return match ($environment) {
            'production' => CheckResult::warning(
                self::CHECK_NAME,
                'Rate limiting is not enabled',
                [
                    'Enable rate limiting in config/security.php to protect against abuse.',
                    'Configure appropriate limits for your API and web routes.',
                    'Consider stricter limits for authentication endpoints.',
                ],
            ),
            'staging' => CheckResult::warning(
                self::CHECK_NAME,
                'Rate limiting is not enabled',
                ['Enable rate limiting in staging to mirror production behavior.'],
            ),
            default => CheckResult::pass(
                self::CHECK_NAME,
                'Rate limiting not required in local environment',
            ),
        };
    }
}
