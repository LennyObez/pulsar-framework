<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\Vex;

use Pulsar\Api\Api;

/**
 * Vulnerability status in a VEX statement.
 *
 * Aligned with the OpenVEX specification status vocabulary.
 */
#[Api(since: '1.0.0')]
enum VexStatus: string
{
    case NotAffected = 'not_affected';
    case Affected = 'affected';
    case Fixed = 'fixed';
    case UnderInvestigation = 'under_investigation';
}
