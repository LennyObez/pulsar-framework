<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use Pulsar\Api\Api;

/**
 * A pluggable compliance check that verifies a specific security control.
 *
 * Implementations inspect runtime configuration, environment settings,
 * or system state to determine whether a control is active.
 * @api
 */
#[Api(since: '1.0.0')]
interface ComplianceCheckInterface
{
    /**
     * Unique identifier for this check (e.g., 'encryption.at_rest').
     */
    public function id(): string;

    /**
     * Human-readable description of what this check verifies.
     */
    public function description(): string;

    /**
     * The compliance domain this check belongs to.
     */
    public function domain(): ComplianceCheckDomain;

    /**
     * Execute the check and return the result.
     */
    public function execute(): CheckResult;
}
