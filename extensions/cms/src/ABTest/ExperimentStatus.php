<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\ABTest;

use Pulsar\Api\Api;

/**
 * @psalm-api Public enum referenced by Experiment::status and the experiment
 *            lifecycle methods; consumed by user-land code.
 */
#[Api(since: '1.0.0')]
enum ExperimentStatus: string
{
    case Draft = 'draft';
    case Running = 'running';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
