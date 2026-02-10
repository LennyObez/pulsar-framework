<?php

declare(strict_types=1);

namespace Pulsar\Api\Filter;

use Pulsar\Api\Api;

/**
 * Types of filter values for type-safe validation.
 */
#[Api(since: '1.0.0')]
enum FilterValueType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Float = 'float';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';
    case Enum = 'enum';
}
