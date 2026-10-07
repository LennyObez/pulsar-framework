<?php

declare(strict_types=1);

namespace Pulsar\Auth\Security;

use Pulsar\Api\Api;

/**
 * Risk level classification for account takeover assessment.
 * @api
 */
#[Api(since: '1.0.0')]
enum TakeoverRiskLevel: string
{
    /** Normal conditions: no elevated risk detected. */
    case Low = 'low';

    /** One risk indicator present (e.g., IP change). */
    case Elevated = 'elevated';

    /** Multiple risk indicators (e.g., IP + device change). */
    case High = 'high';
}
