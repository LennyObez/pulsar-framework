<?php

declare(strict_types=1);

namespace Pulsar\Compliance;

use Pulsar\Api\Api;

/**
 * Represents the implementation status of a regulatory control.
 * @api
 */
#[Api(since: '1.0.0')]
enum ControlStatus: string
{
    case Implemented = 'implemented';
    case Partial = 'partial';
    case Planned = 'planned';
    case NotApplicable = 'not_applicable';
}
