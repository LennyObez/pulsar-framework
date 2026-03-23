<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\FieldRegistry;

use Pulsar\Api\Api;

/**
 * Typed field types for the custom field registry.
 *
 * Each type maps to a specific database value column for proper indexing
 * and type-safe queries.
 *
 * @psalm-api Public enum referenced by ContentTypeField and consumed by user
 *            extension code defining custom content types.
 */
#[Api(since: '1.0.0')]
enum FieldType: string
{
    case String = 'string';
    case Int = 'int';
    case Float = 'float';
    case Bool = 'bool';
    case Date = 'date';
    case DateTime = 'datetime';
    case Enum = 'enum';
    case Relation = 'relation';
    case Json = 'json';
    case Media = 'media';
    case RichText = 'rich_text';
    case Color = 'color';
    case Url = 'url';
    case Email = 'email';

    /**
     * Returns the database column name used to store values of this field type.
     *
     * Only the matching column is populated; all others remain NULL.
     */
    public function valueColumn(): string
    {
        return match ($this) {
            self::String, self::Enum, self::Url, self::Email, self::Color => 'value_string',
            self::Int => 'value_int',
            self::Float => 'value_float',
            self::Bool => 'value_bool',
            self::Date, self::DateTime => 'value_datetime',
            self::Json, self::Relation, self::Media, self::RichText => 'value_json',
        };
    }
}
