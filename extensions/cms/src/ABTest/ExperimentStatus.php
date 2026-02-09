<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\ABTest;

use Pulsar\Api\Api;

#[Api(since: '1.0.0')]
enum ExperimentStatus: string
{
    case Draft = 'draft';
    case Running = 'running';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
