<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Vigilance;

use Pulsar\Api\Api;

/**
 * Status of a vigilance report.
 */
#[Api(since: '1.0.0')]
enum VigilanceReportStatus: string
{
    case Initial = 'initial';
    case FollowUp = 'follow_up';
    case Final = 'final';
    case Closed = 'closed';
}
