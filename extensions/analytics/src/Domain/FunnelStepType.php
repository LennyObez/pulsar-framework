<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use Pulsar\Api\Api;

/**
 * How a funnel step matches visitor actions.
 * @api
 */
#[Api(since: '1.0.0')]
enum FunnelStepType: string
{
    case PageVisit = 'page_visit';
    case CustomEvent = 'custom_event';
}
