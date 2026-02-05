<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use Pulsar\Api\Api;

/**
 * Safe expression allowlist for column default values.
 *
 * Only these expressions may appear as raw SQL in DDL default clauses.
 * Prevents arbitrary SQL injection through default value expressions.
 */
#[Api(since: '1.0.0')]
enum SchemaDefaultExpression: string
{
    case CurrentTimestamp = 'CURRENT_TIMESTAMP';
    case CurrentDate = 'CURRENT_DATE';
    case CurrentTime = 'CURRENT_TIME';
    case True = 'TRUE';
    case False = 'FALSE';
    case Null = 'NULL';
}
