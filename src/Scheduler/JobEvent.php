<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

/**
 * Lifecycle events emitted during job execution.
 */
enum JobEvent: string
{
    case BeforeExecute = 'before_execute';
    case AfterExecute = 'after_execute';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
