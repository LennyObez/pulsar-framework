<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use Pulsar\Api\Api;

/**
 * Type of analytics goal trigger.
 * @api
 */
#[Api(since: '1.0.0')]
enum GoalType: string
{
    case PageVisit = 'page_visit';
    case CustomEvent = 'custom_event';
}
