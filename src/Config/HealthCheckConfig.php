<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for health checks.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HealthCheckConfig
{
    /**
     * @param bool $fipsCheck Register the FIPS 140-2 compliance health check.
     *        Opt-in on purpose: the check reports "degraded" on any host whose
     *        OpenSSL is not running in FIPS mode, and the health endpoint turns
     *        any non-healthy result into a 503 -- correct fail-closed behaviour
     *        for a FIPS-regulated deployment, but it would eject every ordinary
     *        deployment from its load-balancer pool if it were on by default.
     */
    public function __construct(
        public int $intervalSeconds = 30,
        public int $timeoutSeconds = 5,
        public bool $fipsCheck = false,
    ) {}

    /**
     * @param array{
     *     interval_seconds?: int,
     *     timeout_seconds?: int,
     *     fips_check?: bool|int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            intervalSeconds: $data['interval_seconds'] ?? 30,
            timeoutSeconds: $data['timeout_seconds'] ?? 5,
            fipsCheck: (bool) ($data['fips_check'] ?? false),
        );
    }
}
