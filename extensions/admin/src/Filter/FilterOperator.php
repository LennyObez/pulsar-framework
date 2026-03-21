<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Filter;

use Pulsar\Api\Api;

/**
 * Comparison operators for filter conditions.
 * @api
 */
#[Api(since: '1.0.0')]
enum FilterOperator: string
{
    case Equals = 'eq';
    case NotEquals = 'neq';
    case GreaterThan = 'gt';
    case GreaterThanOrEqual = 'gte';
    case LessThan = 'lt';
    case LessThanOrEqual = 'lte';
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
    case In = 'in';
    case NotIn = 'not_in';
    case IsNull = 'is_null';
    case IsNotNull = 'is_not_null';
    case Between = 'between';

    /**
     * Whether this operator requires a value.
     */
    public function requiresValue(): bool
    {
        return match ($this) {
            self::IsNull, self::IsNotNull => false,
            default => true,
        };
    }

    /**
     * Whether this operator accepts multiple values.
     */
    public function isMultiValue(): bool
    {
        return match ($this) {
            self::In, self::NotIn, self::Between => true,
            default => false,
        };
    }

    /**
     * Get the SQL operator equivalent.
     */
    public function toSql(): string
    {
        return match ($this) {
            self::Equals => '=',
            self::NotEquals => '!=',
            self::GreaterThan => '>',
            self::GreaterThanOrEqual => '>=',
            self::LessThan => '<',
            self::LessThanOrEqual => '<=',
            self::Contains, self::StartsWith, self::EndsWith => 'LIKE',
            self::NotContains => 'NOT LIKE',
            self::In => 'IN',
            self::NotIn => 'NOT IN',
            self::IsNull => 'IS NULL',
            self::IsNotNull => 'IS NOT NULL',
            self::Between => 'BETWEEN',
        };
    }
}
