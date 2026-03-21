<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Capa;

use Pulsar\Api\Api;

/**
 * Status of a CAPA (Corrective and Preventive Action) per ISO 13485 Section 8.5.
 * @api
 */
#[Api(since: '1.0.0')]
enum CapaStatus: string
{
    case Initiated = 'initiated';
    case Investigating = 'investigating';
    case ActionPlanned = 'action_planned';
    case ActionImplemented = 'action_implemented';
    case EffectivenessVerified = 'effectiveness_verified';
    case Closed = 'closed';
}
