<?php

declare(strict_types=1);

namespace Pulsar\Deploy;

use Pulsar\Api\Api;

/**
 * Contract for deploy readiness checks.
 *
 * Each check validates a specific aspect of the deployment configuration
 * and returns a result with a severity and actionable recommendations.
 * @api
 */
#[Api(since: '1.0.0')]
interface DeployCheckInterface
{
    /**
     * Get the unique name of this check.
     */
    public function getName(): string;

    /**
     * Get a human-readable description of what this check validates.
     */
    public function getDescription(): string;

    /**
     * Run the check against the given target environment.
     *
     * @param string $environment The target deployment environment (local, staging, production)
     */
    public function check(string $environment): CheckResult;
}
