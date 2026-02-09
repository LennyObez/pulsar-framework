<?php

declare(strict_types=1);

namespace Pulsar\Supervisor\PreflightCheck;

use Pulsar\Api\Api;

/**
 * Contract for supervisor preflight checks.
 *
 * Preflight checks run before the supervisor starts accepting work
 * to verify that the runtime environment meets minimum requirements.
 */
#[Api(since: '1.0.0')]
interface PreflightCheckInterface
{
    /**
     * Human-readable name of this check.
     */
    public function getName(): string;

    /**
     * Execute the preflight check and return the result.
     */
    public function check(): PreflightCheckResult;
}
