<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\Vex;

use Pulsar\Api\Api;

/**
 * Justification for a "not_affected" VEX status.
 *
 * Each value explains why the product is not affected by the vulnerability,
 * per the OpenVEX specification justification vocabulary.
 */
#[Api(since: '1.0.0')]
enum VexJustification: string
{
    case ComponentNotPresent = 'component_not_present';
    case VulnerableCodeNotPresent = 'vulnerable_code_not_present';
    case VulnerableCodeNotInExecutePath = 'vulnerable_code_not_in_execute_path';
    case InlineMitigationsAlreadyExist = 'inline_mitigations_already_exist';
}
