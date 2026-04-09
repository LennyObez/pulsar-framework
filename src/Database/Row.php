<?php

declare(strict_types=1);

namespace Pulsar\Database;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Database\Exception\DatabaseException;

use function array_key_exists;
use function array_keys;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_resource;
use function is_string;
use function stream_get_contents;

/**
 * Readonly single-row value object with typed accessors.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Row
{
    /**
     * @param array<string, mixed> $data Column name => value pairs
     */
    public function __construct(
        public array $data,
    ) {}

    /**
     * Get a raw column value.
     *
     * @throws DatabaseException If the column does not exist.
     */
    #[NoDiscard]
    public function get(string $column): mixed
    {
        if (!array_key_exists($column, $this->data)) {
            throw DatabaseException::columnNotFound($column);
        }

        return $this->data[$column];
    }

    /**
     * Get a column value with a default fallback.
     *
     * @param mixed $default Fallback value if the column does not exist
     */
    public function getOrDefault(string $column, mixed $default = null): mixed
    {
        if (!array_key_exists($column, $this->data)) {
            return $default;
        }

        return $this->data[$column];
    }

    /**
     * Get a column as an integer.
     *
     * @throws DatabaseException If the column does not exist or cannot be cast.
     */
    public function getInt(string $column): int
    {
        $value = $this->get($column);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '' && ($value === '0' || ltrim($value, '-0123456789') === '')) {
            return (int) $value;
        }

        throw DatabaseException::typeCastFailed($column, 'int');
    }

    /**
     * Get a column as a string.
     *
     * @throws DatabaseException If the column does not exist or cannot be cast.
     */
    public function getString(string $column): string
    {
        $value = $this->get($column);

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw DatabaseException::typeCastFailed($column, 'string');
    }

    /**
     * Get a column as a boolean.
     *
     * @throws DatabaseException If the column does not exist or cannot be cast.
     */
    public function getBool(string $column): bool
    {
        $value = $this->get($column);

        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === '1') {
            return true;
        }

        if ($value === 0 || $value === '0') {
            return false;
        }

        throw DatabaseException::typeCastFailed($column, 'bool');
    }

    /**
     * Get a column as a float.
     *
     * @throws DatabaseException If the column does not exist or cannot be cast.
     */
    public function getFloat(string $column): float
    {
        $value = $this->get($column);

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw DatabaseException::typeCastFailed($column, 'float');
    }

    /**
     * Get a column as a nullable integer.
     *
     * @throws DatabaseException If the column does not exist.
     */
    public function getNullableInt(string $column): ?int
    {
        $value = $this->get($column);

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '' && ($value === '0' || ltrim($value, '-0123456789') === '')) {
            return (int) $value;
        }

        throw DatabaseException::typeCastFailed($column, 'int');
    }

    /**
     * Get a column as a nullable float.
     *
     * @throws DatabaseException If the column does not exist.
     */
    public function getNullableFloat(string $column): ?float
    {
        $value = $this->get($column);

        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw DatabaseException::typeCastFailed($column, 'float');
    }

    /**
     * Get a column as raw binary bytes.
     *
     * Normalizes PostgreSQL bytea reads where PDO may return a resource (stream)
     * instead of a plain string, depending on PDO driver version and fetch mode.
     * All callers receive a plain string regardless of driver behavior.
     *
     * @throws DatabaseException If the column does not exist or cannot be read.
     */
    public function getBinary(string $column): string
    {
        $value = $this->get($column);

        if (is_string($value)) {
            return $value;
        }

        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            if ($contents === false) {
                throw DatabaseException::typeCastFailed($column, 'binary');
            }

            return $contents;
        }

        throw DatabaseException::typeCastFailed($column, 'binary');
    }

    /**
     * Get a column as a nullable string.
     *
     * @throws DatabaseException If the column does not exist.
     */
    public function getNullableString(string $column): ?string
    {
        $value = $this->get($column);

        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw DatabaseException::typeCastFailed($column, 'string');
    }

    /**
     * Check if a column exists in this row.
     */
    public function has(string $column): bool
    {
        return array_key_exists($column, $this->data);
    }

    /**
     * Get all column names.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return array_keys($this->data);
    }

    /**
     * Get the row data as a plain array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
