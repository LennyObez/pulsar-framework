<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Internal\Support;

use DateTimeImmutable;
use DateTimeInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Orm\Domain\ColumnType;

use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Casts values between PHP types and database column types.
 */
#[Internal]
final readonly class TypeCaster
{
    /**
     * Cast a database value to a PHP type based on column metadata.
     */
    public function fromDatabase(mixed $value, ColumnType $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            ColumnType::String,
            ColumnType::Text,
            ColumnType::Uuid,
            ColumnType::Enum,
            ColumnType::BigInt,
            ColumnType::Time,
            ColumnType::Binary => is_string($value) ? $value : (string) $value,
            ColumnType::Integer,
            ColumnType::SmallInt => is_int($value) ? $value : (int) $value,
            ColumnType::Float,
            ColumnType::Decimal => is_float($value) ? $value : (float) $value,
            ColumnType::Boolean => is_bool($value) ? $value : (bool) $value,
            ColumnType::DateTime,
            ColumnType::Date => $value instanceof DateTimeImmutable
                ? $value
                : new DateTimeImmutable(is_string($value) ? $value : (string) $value),
            ColumnType::Json => is_string($value)
                ? json_decode($value, true, 512, JSON_THROW_ON_ERROR)
                : $value,
        };
    }

    /**
     * Cast a PHP value to a database-compatible value.
     */
    public function toDatabase(mixed $value, ColumnType $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            ColumnType::String,
            ColumnType::Text,
            ColumnType::Uuid,
            ColumnType::Enum,
            ColumnType::BigInt,
            ColumnType::Binary => (string) $value,
            ColumnType::Integer,
            ColumnType::SmallInt => (int) $value,
            ColumnType::Float,
            ColumnType::Decimal => is_string($value) ? $value : (string) (float) $value,
            ColumnType::Boolean => $value ? 1 : 0,
            ColumnType::DateTime => $value instanceof DateTimeInterface
                ? $value->format('Y-m-d H:i:s')
                : (string) $value,
            ColumnType::Date => $value instanceof DateTimeInterface
                ? $value->format('Y-m-d')
                : (string) $value,
            ColumnType::Time => $value instanceof DateTimeInterface
                ? $value->format('H:i:s')
                : (string) $value,
            ColumnType::Json => is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR),
        };
    }
}
