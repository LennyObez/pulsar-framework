<?php

declare(strict_types=1);

namespace Pulsar\Supervisor\PreflightCheck;

use Override;
use Pulsar\Api\Internal;

use function disk_free_space;
use function sprintf;

/**
 * Verifies that available disk space on the target path meets the
 * configured minimum threshold.
 */
#[Internal]
final class DiskSpacePreflightCheck implements PreflightCheckInterface
{
    private const int BYTES_PER_MB = 1_048_576;

    public function __construct(
        private readonly string $path = '/',
        private readonly int $minimumFreeMb = 100,
    ) {}

    #[Override]
    public function getName(): string
    {
        return 'disk_space';
    }

    #[Override]
    public function check(): PreflightCheckResult
    {
        $freeBytes = @disk_free_space($this->path);

        if ($freeBytes === false) {
            return new PreflightCheckResult(
                passed: false,
                message: sprintf('Unable to determine free disk space for path "%s"', $this->path),
                findings: [sprintf('Path: %s', $this->path)],
            );
        }

        $freeMb = (int) ($freeBytes / (float) self::BYTES_PER_MB);
        $findings = [
            sprintf('Path: %s', $this->path),
            sprintf('Free disk space: %d MB', $freeMb),
            sprintf('Minimum required: %d MB', $this->minimumFreeMb),
        ];

        if ($freeMb < $this->minimumFreeMb) {
            return new PreflightCheckResult(
                passed: false,
                message: sprintf(
                    'Free disk space (%d MB) is below minimum (%d MB)',
                    $freeMb,
                    $this->minimumFreeMb,
                ),
                findings: $findings,
            );
        }

        return new PreflightCheckResult(
            passed: true,
            message: sprintf('Free disk space (%d MB) meets minimum requirement', $freeMb),
            findings: $findings,
        );
    }
}
