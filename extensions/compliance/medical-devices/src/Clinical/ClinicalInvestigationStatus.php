<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Clinical;

use Pulsar\Api\Api;

/**
 * Status of a clinical investigation.
 * @api
 */
#[Api(since: '1.0.0')]
enum ClinicalInvestigationStatus: string
{
    case Planned = 'planned';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Recruiting = 'recruiting';
    case Active = 'active';
    case Completed = 'completed';
    case Terminated = 'terminated';
    case Suspended = 'suspended';
}
