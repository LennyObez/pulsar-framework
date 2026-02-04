<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

/**
 * Execution status of a scheduled job.
 */
enum JobStatus: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Skipped = 'skipped';
    case Running = 'running';
}
