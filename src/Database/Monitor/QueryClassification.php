<?php

declare(strict_types=1);

namespace Pulsar\Database\Monitor;

use Pulsar\Api\Api;

/**
 * Classifies a SQL statement by its primary operation type.
 * @api
 */
#[Api(since: '1.0.0')]
enum QueryClassification: string
{
    case Select = 'select';
    case Insert = 'insert';
    case Update = 'update';
    case Delete = 'delete';
    case Ddl = 'ddl';
}
