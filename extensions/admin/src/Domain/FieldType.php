<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Domain;

use Pulsar\Api\Api;

/**
 * Supported field types for admin resource definitions.
 */
#[Api(since: '1.0.0')]
enum FieldType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Float = 'float';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';
    case Email = 'email';
    case Url = 'url';
    case Json = 'json';
    case Enum = 'enum';
    case Relation = 'relation';
}
