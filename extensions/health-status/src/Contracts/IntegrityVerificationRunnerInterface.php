<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Contracts;

use Pulsar\Api\Api;
use Pulsar\Integrity\VerificationResult;

/**
 * Runs integrity verification and returns a result.
 *
 * Abstracts manifest loading and filesystem verification so that
 * controllers can be tested without touching the filesystem.
 */
#[Api(since: '1.0.0')]
interface IntegrityVerificationRunnerInterface
{
    /**
     * Run integrity verification using the configured manifest.
     *
     * Returns a passing empty result if verification is disabled
     * or the manifest cannot be loaded.
     */
    public function run(): VerificationResult;
}
