<?php

declare(strict_types=1);

namespace Pulsar\Api\Sort;

use Pulsar\Api\Api;

/**
 * Sort direction for API sort expressions.
 * @api
 */
#[Api(since: '1.0.0')]
enum SortDirection: string
{
    case Ascending = 'asc';
    case Descending = 'desc';
}
