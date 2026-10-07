<?php

declare(strict_types=1);

namespace Pulsar\Security\Posture;

use Override;
use Pulsar\Api\Api;
use Pulsar\Resilience\HealthCheck\HealthCheckInterface;
use Pulsar\Resilience\HealthCheck\HealthCheckResult;

use function count;
use function sprintf;

/**
 * Surfaces the security-posture preflight through the standard health endpoint
 * and `health:check`, so an inert security control marks the deployment
 * UNHEALTHY rather than only living in a log line.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SecurityPostureHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private SecurityPostureReport $report,
    ) {}

    #[Override]
    public function getName(): string
    {
        return 'security_posture';
    }

    #[Override]
    public function check(): HealthCheckResult
    {
        $name = $this->getName();

        if ($this->report->hasFailures()) {
            return HealthCheckResult::unhealthy(
                $name,
                sprintf(
                    '%d security control(s) failing, %d degraded — run `security:check`',
                    count($this->report->failures()),
                    count($this->report->degraded()),
                ),
            );
        }

        if ($this->report->hasDegraded()) {
            return HealthCheckResult::degraded(
                $name,
                sprintf('%d security control(s) degraded — run `security:check`', count($this->report->degraded())),
            );
        }

        return HealthCheckResult::healthy($name, 'All security controls OK');
    }
}
