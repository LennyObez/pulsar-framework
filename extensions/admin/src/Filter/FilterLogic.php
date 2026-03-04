<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Filter;

use Pulsar\Api\Api;

/**
 * Logical operators for combining filter conditions.
 */
#[Api(since: '1.0.0')]
enum FilterLogic: string
{
    case And = 'and';
    case Or = 'or';
}
