<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Internal\Support;

use DateTimeImmutable;
use DateTimeInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Orm\Domain\ColumnType;

use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_scalar;
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
     *
     * @return null|string|int|float|bool|DateTimeImmutable|array<array-key, mixed>
     */
    public function fromDatabase(mixed $value, ColumnType $type): null|string|int|float|bool|DateTimeImmutable|array
    {
        if ($value === null) {
            return null;
        }

        if ($type === ColumnType::Json) {
            if (is_string($value)) {
                /** @var array<array-key, mixed> */
                return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            }
            return is_array($value) ? $value : [];
        }

        return match ($type) {
            ColumnType::String,
            ColumnType::Text,
            ColumnType::Uuid,
            ColumnType::Enum,
            ColumnType::BigInt,
            ColumnType::Time,
            ColumnType::Binary => is_string($value) ? $value : (is_scalar($value) ? (string) $value : ''),
            ColumnType::Integer,
            ColumnType::SmallInt => is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0),
            ColumnType::Float,
            ColumnType::Decimal => is_float($value) ? $value : (is_numeric($value) ? (float) $value : 0.0),
            ColumnType::Boolean => is_bool($value) ? $value : (bool) $value,
            ColumnType::DateTime,
            ColumnType::Date => $value instanceof DateTimeImmutable
                ? $value
                : new DateTimeImmutable(is_string($value) ? $value : (is_scalar($value) ? (string) $value : 'now')),
        };
    }

    /**
     * Cast a PHP value to a database-compatible value.
     */
    public function toDatabase(mixed $value, ColumnType $type): null|string|int
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
            ColumnType::Binary => is_string($value) ? $value : (is_scalar($value) ? (string) $value : ''),
            ColumnType::Integer,
            ColumnType::SmallInt => is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0),
            ColumnType::Float,
            ColumnType::Decimal => is_string($value) ? $value : (is_numeric($value) ? (string) (float) $value : '0'),
            ColumnType::Boolean => $value ? 1 : 0,
            ColumnType::DateTime => $value instanceof DateTimeInterface
                ? $value->format('Y-m-d H:i:s')
                : (is_string($value) ? $value : (is_scalar($value) ? (string) $value : '')),
            ColumnType::Date => $value instanceof DateTimeInterface
                ? $value->format('Y-m-d')
                : (is_string($value) ? $value : (is_scalar($value) ? (string) $value : '')),
            ColumnType::Time => $value instanceof DateTimeInterface
                ? $value->format('H:i:s')
                : (is_string($value) ? $value : (is_scalar($value) ? (string) $value : '')),
            ColumnType::Json => is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR),
        };
    }
}
