<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use function count;

use Pulsar\Api\Internal;
use Pulsar\Config\DeployConfig;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;

use function sprintf;

/**
 * Validates that trusted proxies are configured for reverse-proxy deployments.
 *
 * In staging and production, applications typically run behind a load balancer
 * or reverse proxy and need trusted proxy configuration to correctly resolve
 * client IPs, protocol, and host.
 */
#[Internal]
final readonly class TrustedProxyCheck implements DeployCheckInterface
{
    private const string CHECK_NAME = 'trusted-proxies';

    public function __construct(
        private DeployConfig $deployConfig,
    ) {}

    public function getName(): string
    {
        return self::CHECK_NAME;
    }

    public function getDescription(): string
    {
        return 'Validates trusted proxies are configured for reverse-proxy environments';
    }

    public function check(string $environment): CheckResult
    {
        if ($this->deployConfig->trustedProxies !== []) {
            return CheckResult::pass(
                self::CHECK_NAME,
                sprintf(
                    'Trusted proxies configured (%d entries)',
                    count($this->deployConfig->trustedProxies),
                ),
            );
        }

        return match ($environment) {
            'production', 'staging' => CheckResult::warning(
                self::CHECK_NAME,
                'No trusted proxies configured',
                [
                    'If running behind a reverse proxy or load balancer, configure trusted proxy IPs.',
                    'Add trusted proxy CIDR ranges in config/deploy.php under "trusted_proxies".',
                    'This is safe to ignore if the application receives traffic directly.',
                ],
            ),
            default => CheckResult::pass(
                self::CHECK_NAME,
                'Trusted proxies not required in local environment',
            ),
        };
    }
}
