<?php

declare(strict_types=1);

namespace Pulsar\Database;

use PDO;
use Pulsar\Api\Api;

/**
 * Fetch mode for query results.
 * @api
 */
#[Api(since: '1.0.0')]
enum FetchMode: int
{
    case Associative = PDO::FETCH_ASSOC;
    case Numeric = PDO::FETCH_NUM;
    case Both = PDO::FETCH_BOTH;
    case Column = PDO::FETCH_COLUMN;
}
