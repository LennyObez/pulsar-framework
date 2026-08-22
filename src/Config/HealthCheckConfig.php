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
final readonly class HealthCheckConfig implements ReportsUnknownKeys
{
    /** Keys read from the `health_check` sub-array of config/resilience.php. */
    private const array KNOWN_KEYS = ['interval_seconds', 'timeout_seconds', 'fips_check'];

    /**
     * @param bool $fipsCheck Register the FIPS 140-2 compliance health check.
     *        Opt-in on purpose: the check reports "degraded" on any host whose
     *        OpenSSL is not running in FIPS mode, and the health endpoint turns
     *        any non-healthy result into a 503 -- correct fail-closed behaviour
     *        for a FIPS-regulated deployment, but it would eject every ordinary
     *        deployment from its load-balancer pool if it were on by default.
     * @param list<string> $unknownKeys Keys present in the raw `health_check` array
     *        that this DTO does not read — `fips_check` misspelled reads as "off",
     *        so a FIPS-regulated deployment loses the check it asked for.
     */
    public function __construct(
        public int $intervalSeconds = 30,
        public int $timeoutSeconds = 5,
        public bool $fipsCheck = false,
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

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
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
