<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

use Pulsar\Api\Api;

/**
 * Execution status of a scheduled job.
 */
#[Api]
enum JobStatus: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Skipped = 'skipped';
    case Running = 'running';
}
