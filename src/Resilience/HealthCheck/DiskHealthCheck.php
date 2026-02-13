<?php

declare(strict_types=1);

namespace Pulsar\Resilience\HealthCheck;

use Override;
use Throwable;

use function disk_free_space;
use function microtime;
use function round;
use function sprintf;

/**
 * Health check that verifies available disk space on a given mount point.
 *
 * Returns unhealthy when the free space falls below the configured threshold.
 */
final readonly class DiskHealthCheck implements HealthCheckInterface
{
    private const int DEFAULT_THRESHOLD_MB = 100;

    public function __construct(
        private string $path = '/',
        private int $thresholdMb = self::DEFAULT_THRESHOLD_MB,
    ) {}

    #[Override]
    public function getName(): string
    {
        return 'disk';
    }

    #[Override]
    public function check(): HealthCheckResult
    {
        $start = microtime(true);

        try {
            $freeBytes = disk_free_space($this->path);

            $elapsed = (microtime(true) - $start) * 1000.0;

            if ($freeBytes === false) {
                return HealthCheckResult::unhealthy(
                    $this->getName(),
                    sprintf('Unable to determine free space for path: %s', $this->path),
                    round($elapsed, 2),
                );
            }

            $freeMb = $freeBytes / 1_048_576.0;

            if ($freeMb < $this->thresholdMb) {
                return HealthCheckResult::unhealthy(
                    $this->getName(),
                    sprintf(
                        'Disk space low: %.0f MB free (threshold: %d MB) on %s',
                        $freeMb,
                        $this->thresholdMb,
                        $this->path,
                    ),
                    round($elapsed, 2),
                );
            }

            return HealthCheckResult::healthy(
                $this->getName(),
                sprintf('%.0f MB free on %s', $freeMb, $this->path),
                round($elapsed, 2),
            );
        } catch (Throwable $e) {
            $elapsed = (microtime(true) - $start) * 1000.0;

            return HealthCheckResult::unhealthy(
                $this->getName(),
                sprintf('Disk check failed: %s', $e->getMessage()),
                round($elapsed, 2),
            );
        }
    }
}
