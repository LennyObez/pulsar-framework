<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Domain;

use Pulsar\Api\Api;

/**
 * Sort direction for ORDER BY clauses.
 */
#[Api(since: '1.0.0')]
enum SortDirection: string
{
    case Asc = 'ASC';
    case Desc = 'DESC';
}
