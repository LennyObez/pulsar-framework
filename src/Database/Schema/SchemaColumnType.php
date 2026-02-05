<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use Pulsar\Api\Api;

/**
 * Supported column types for schema DDL operations.
 */
#[Api(since: '1.0.0')]
enum SchemaColumnType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case SmallInt = 'smallint';
    case BigInt = 'bigint';
    case Float = 'float';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case DateTime = 'datetime';
    case Date = 'date';
    case Time = 'time';
    case Json = 'json';
    case Uuid = 'uuid';
    case Binary = 'binary';
    case Enum = 'enum';
}
