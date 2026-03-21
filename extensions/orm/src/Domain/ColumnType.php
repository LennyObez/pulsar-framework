<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Domain;

use Pulsar\Api\Api;

/**
 * Supported column data types for entity mapping.
 * @api
 */
#[Api(since: '1.0.0')]
enum ColumnType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Float = 'float';
    case Boolean = 'boolean';
    case Text = 'text';
    case Binary = 'binary';
    case DateTime = 'datetime';
    case Date = 'date';
    case Time = 'time';
    case Json = 'json';
    case Uuid = 'uuid';
    case Decimal = 'decimal';
    case SmallInt = 'smallint';
    case BigInt = 'bigint';
    case Enum = 'enum';
}
