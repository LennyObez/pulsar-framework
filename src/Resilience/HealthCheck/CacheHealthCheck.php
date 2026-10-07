<?php

declare(strict_types=1);

namespace Pulsar\Resilience\HealthCheck;

use Override;
use Psr\SimpleCache\CacheInterface;
use Throwable;

use function microtime;
use function round;
use function sprintf;

/**
 * Health check that verifies the application cache backend is reachable.
 *
 * Performs a write-read-delete cycle with a sentinel key and reports
 * the round-trip latency. Returns degraded when latency exceeds 500 ms.
 */
final readonly class CacheHealthCheck implements HealthCheckInterface
{
    private const string SENTINEL_KEY = '_pulsar_health_check';

    private const string SENTINEL_VALUE = '1';

    /** Latency threshold in milliseconds above which the cache is considered degraded. */
    private const float DEGRADED_THRESHOLD_MS = 500.0;

    public function __construct(
        private CacheInterface $cache,
    ) {}

    #[Override]
    public function getName(): string
    {
        return 'cache';
    }

    #[Override]
    public function check(): HealthCheckResult
    {
        $start = microtime(true);

        try {
            $this->cache->set(self::SENTINEL_KEY, self::SENTINEL_VALUE, 30);

            $retrieved = $this->cache->get(self::SENTINEL_KEY);

            $this->cache->delete(self::SENTINEL_KEY);

            $elapsed = (microtime(true) - $start) * 1000.0;

            if ($retrieved !== self::SENTINEL_VALUE) {
                return HealthCheckResult::unhealthy(
                    $this->getName(),
                    'Cache read-back mismatch: wrote sentinel but read different value',
                    round($elapsed, 2),
                );
            }

            if ($elapsed > self::DEGRADED_THRESHOLD_MS) {
                return HealthCheckResult::degraded(
                    $this->getName(),
                    sprintf('Cache responded in %.1fms (slow)', $elapsed),
                    round($elapsed, 2),
                );
            }

            return HealthCheckResult::healthy(
                $this->getName(),
                sprintf('Cache responded in %.1fms', $elapsed),
                round($elapsed, 2),
            );
        } catch (Throwable $e) {
            $elapsed = (microtime(true) - $start) * 1000.0;

            return HealthCheckResult::unhealthy(
                $this->getName(),
                sprintf('Cache check failed: %s', $e->getMessage()),
                round($elapsed, 2),
            );
        }
    }
}
