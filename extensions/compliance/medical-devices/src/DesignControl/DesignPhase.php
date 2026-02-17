<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\DesignControl;

use Pulsar\Api\Api;

/**
 * Design and development lifecycle phases per ISO 13485 Section 7.3.
 */
#[Api(since: '1.0.0')]
enum DesignPhase: string
{
    case Planning = 'planning';
    case InputDefinition = 'input_definition';
    case Design = 'design';
    case OutputDocumentation = 'output_documentation';
    case Review = 'review';
    case Verification = 'verification';
    case Validation = 'validation';
    case Transfer = 'transfer';
    case Complete = 'complete';
}
