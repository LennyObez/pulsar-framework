<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use Pulsar\Api\Api;

/**
 * Comparison operators for segment filters.
 * @api
 */
#[Api(since: '1.0.0')]
enum SegmentOperator: string
{
    case Equals = 'eq';
    case NotEquals = 'neq';
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case StartsWith = 'starts_with';
    case GreaterThan = 'gt';
    case LessThan = 'lt';
    case In = 'in';
}
