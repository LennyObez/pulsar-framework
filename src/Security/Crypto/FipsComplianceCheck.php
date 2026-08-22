<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use Override;
use Pulsar\Api\Api;
use Pulsar\Resilience\HealthCheck\HealthCheckInterface;
use Pulsar\Resilience\HealthCheck\HealthCheckResult;

use function microtime;
use function round;

/**
 * Deploy-time health check that verifies FIPS 140-2 compliance.
 *
 * Register with the HealthCheckRunner to verify FIPS mode at boot or
 * during deployment checks. Reports healthy when the OpenSSL FIPS provider
 * is active and all required algorithms are available.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FipsComplianceCheck implements HealthCheckInterface
{
    #[Override]
    public function getName(): string
    {
        return 'fips-compliance';
    }

    #[Override]
    public function check(): HealthCheckResult
    {
        $start = microtime(true);

        $result = FipsValidator::verify();

        $elapsed = round((microtime(true) - $start) * 1000.0, 2);

        if ($result->compliant) {
            return HealthCheckResult::healthy(
                $this->getName(),
                'FIPS 140-2 compliant (' . $result->opensslVersion . ')',
                $elapsed,
            );
        }

        // AES-256-GCM is the bare minimum for Pulsar to function
        if (!$result->aes256GcmAvailable) {
            return HealthCheckResult::unhealthy(
                $this->getName(),
                $result->summary(),
                $elapsed,
            );
        }

        // FIPS mode not detected but algorithms are available; degraded
        return HealthCheckResult::degraded(
            $this->getName(),
            $result->summary(),
            $elapsed,
        );
    }
}
