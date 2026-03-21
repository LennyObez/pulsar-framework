<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Repair;

use Pulsar\Api\Api;

/**
 * Interface for self-healing repair jobs.
 * @api
 */
#[Api(since: '1.0.0')]
interface RepairJobInterface
{
    /**
     * Get the repair job name.
     */
    public function getName(): string;

    /**
     * Get a description of what this repair job does.
     */
    public function getDescription(): string;

    /**
     * Diagnose whether repair is needed.
     */
    public function diagnose(): RepairDiagnosis;

    /**
     * Attempt to repair the diagnosed issue.
     */
    public function repair(): RepairResult;
}
