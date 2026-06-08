<?php

declare(strict_types=1);

namespace Pulsar\Supervisor\PreflightCheck;

use Override;
use Pulsar\Api\Internal;

use function memory_get_usage;
use function round;
use function sprintf;

/**
 * Verifies that current memory usage is below a configurable threshold.
 *
 * This check runs before the supervisor begins accepting work to ensure
 * the process is not already under memory pressure.
 */
#[Internal]
final class MemoryPreflightCheck implements PreflightCheckInterface
{
    private const int BYTES_PER_MB = 1_048_576;

    public function __construct(
        private readonly int $thresholdMb = 256,
    ) {}

    #[Override]
    public function getName(): string
    {
        return 'memory';
    }

    #[Override]
    public function check(): PreflightCheckResult
    {
        $usageBytes = memory_get_usage(true);
        $usageMb = (int) round($usageBytes / self::BYTES_PER_MB);
        $findings = [
            sprintf('Current memory usage: %d MB', $usageMb),
            sprintf('Threshold: %d MB', $this->thresholdMb),
        ];

        if ($usageMb >= $this->thresholdMb) {
            return new PreflightCheckResult(
                passed: false,
                message: sprintf(
                    'Memory usage (%d MB) exceeds threshold (%d MB)',
                    $usageMb,
                    $this->thresholdMb,
                ),
                findings: $findings,
            );
        }

        return new PreflightCheckResult(
            passed: true,
            message: sprintf('Memory usage (%d MB) is within threshold', $usageMb),
            findings: $findings,
        );
    }
}
