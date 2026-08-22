<?php

declare(strict_types=1);

namespace Pulsar\Api\Filter;

use Pulsar\Api\Api;

/**
 * Enumerated filter operators.
 *
 * Only these operators are permitted in API filter expressions.
 * Unknown operators are rejected with 400 Bad Request.
 *
 * This is a Finding E invariant: no arbitrary SQL/expression injection
 * is possible via filter parameters.
 * @api
 */
#[Api(since: '1.0.0')]
enum FilterOperator: string
{
    case Equal = 'eq';
    case NotEqual = 'neq';
    case GreaterThan = 'gt';
    case GreaterThanOrEqual = 'gte';
    case LessThan = 'lt';
    case LessThanOrEqual = 'lte';
    case In = 'in';
    case Contains = 'contains';
    case StartsWith = 'starts_with';
}
