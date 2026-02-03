<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use Pulsar\Api\Api;

/**
 * Aggregate result of verifying an entire integrity manifest against the filesystem.
 */
#[Api]
final readonly class VerificationResult
{
    /**
     * @param list<FileVerificationResult> $files Individual file verification results
     */
    public function __construct(
        public bool $passed,
        public int $verified,
        public int $modified,
        public int $missing,
        public int $added,
        public array $files,
    ) {}
}
