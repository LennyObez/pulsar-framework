<?php

declare(strict_types=1);

namespace Pulsar\Support\Mapper;

use Pulsar\Api\Api;

/**
 * Naming strategy for mapping array keys to constructor parameter names.
 */
#[Api(since: '1.0.0')]
enum NamingStrategy: string
{
    /** No transformation: keys must match parameter names exactly. */
    case Identity = 'identity';

    /** Convert camelCase parameter names to snake_case when looking up keys. */
    case CamelToSnake = 'camel_to_snake';

    /** Convert snake_case keys to camelCase parameter names. */
    case SnakeToCamel = 'snake_to_camel';
}
