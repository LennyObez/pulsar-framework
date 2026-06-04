<?php

declare(strict_types=1);

namespace Pulsar\Config\Validation;

use NoDiscard;
use Pulsar\Api\Api;

use function array_key_exists;
use function count;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Validates raw config arrays against type and constraint rules.
 *
 * Provides a fluent builder for assembling validation rules on
 * raw config arrays before they are converted into typed DTOs.
 * @api
 */
#[Api(since: '1.0.0')]
final class ConfigValidator
{
    /** @var list<ConfigValidationError> */
    private array $errors = [];

    private readonly string $context;

    /** @var array<string, mixed> */
    private readonly array $data;

    /**
     * @param array<string, mixed> $data Raw config array to validate
     * @param string $context Human-readable config context (e.g., "config/app.php")
     */
    public function __construct(array $data, string $context)
    {
        $this->data = $data;
        $this->context = $context;
    }

    /**
     * Require a key to be present and non-null.
     */
    public function required(string $key): self
    {
        if (!array_key_exists($key, $this->data) || $this->data[$key] === null) {
            $this->errors[] = ConfigValidationError::required($this->qualifiedKey($key));
        }

        return $this;
    }

    /**
     * Validate that a key, if present, is a string.
     */
    public function string(string $key): self
    {
        if (array_key_exists($key, $this->data) && $this->data[$key] !== null && !is_string($this->data[$key])) {
            $this->errors[] = ConfigValidationError::invalidType(
                $this->qualifiedKey($key),
                'string',
                get_debug_type($this->data[$key]),
            );
        }

        return $this;
    }

    /**
     * Validate that a key, if present, is a boolean.
     */
    public function boolean(string $key): self
    {
        if (array_key_exists($key, $this->data) && $this->data[$key] !== null && !is_bool($this->data[$key])) {
            $this->errors[] = ConfigValidationError::invalidType(
                $this->qualifiedKey($key),
                'bool',
                get_debug_type($this->data[$key]),
            );
        }

        return $this;
    }

    /**
     * Validate that a key, if present, is an integer.
     */
    public function integer(string $key): self
    {
        if (array_key_exists($key, $this->data) && $this->data[$key] !== null && !is_int($this->data[$key])) {
            $this->errors[] = ConfigValidationError::invalidType(
                $this->qualifiedKey($key),
                'int',
                get_debug_type($this->data[$key]),
            );
        }

        return $this;
    }

    /**
     * Validate that a key, if present, is an array.
     */
    public function array(string $key): self
    {
        if (array_key_exists($key, $this->data) && $this->data[$key] !== null && !is_array($this->data[$key])) {
            $this->errors[] = ConfigValidationError::invalidType(
                $this->qualifiedKey($key),
                'array',
                get_debug_type($this->data[$key]),
            );
        }

        return $this;
    }

    /**
     * Validate an integer is within a range (inclusive).
     */
    public function intRange(string $key, int $min, int $max): self
    {
        if (!array_key_exists($key, $this->data) || !is_int($this->data[$key])) {
            return $this;
        }

        $value = $this->data[$key];

        if ($value < $min || $value > $max) {
            $this->errors[] = ConfigValidationError::outOfRange(
                $this->qualifiedKey($key),
                sprintf('must be between %d and %d, got %d', $min, $max, $value),
            );
        }

        return $this;
    }

    /**
     * Validate a positive integer (> 0).
     */
    public function positiveInt(string $key): self
    {
        if (!array_key_exists($key, $this->data) || !is_int($this->data[$key])) {
            return $this;
        }

        if ($this->data[$key] <= 0) {
            $this->errors[] = ConfigValidationError::outOfRange(
                $this->qualifiedKey($key),
                sprintf('must be positive, got %d', $this->data[$key]),
            );
        }

        return $this;
    }

    /**
     * Validate a non-negative integer (>= 0).
     */
    public function nonNegativeInt(string $key): self
    {
        if (!array_key_exists($key, $this->data) || !is_int($this->data[$key])) {
            return $this;
        }

        if ($this->data[$key] < 0) {
            $this->errors[] = ConfigValidationError::outOfRange(
                $this->qualifiedKey($key),
                sprintf('must be non-negative, got %d', $this->data[$key]),
            );
        }

        return $this;
    }

    /**
     * Validate a string matches one of the allowed values.
     *
     * @param list<string> $allowed
     */
    public function oneOf(string $key, array $allowed): self
    {
        if (!array_key_exists($key, $this->data) || !is_string($this->data[$key])) {
            return $this;
        }

        if (!in_array($this->data[$key], $allowed, true)) {
            $this->errors[] = ConfigValidationError::outOfRange(
                $this->qualifiedKey($key),
                sprintf('must be one of [%s], got "%s"', implode(', ', $allowed), $this->data[$key]),
            );
        }

        return $this;
    }

    /**
     * Validate a string matches a regex pattern.
     */
    public function matches(string $key, string $pattern, string $formatDescription): self
    {
        if (!array_key_exists($key, $this->data) || !is_string($this->data[$key])) {
            return $this;
        }

        if (preg_match($pattern, $this->data[$key]) !== 1) {
            $this->errors[] = ConfigValidationError::invalidFormat(
                $this->qualifiedKey($key),
                $formatDescription,
            );
        }

        return $this;
    }

    /**
     * Validate a non-empty string.
     */
    public function nonEmptyString(string $key): self
    {
        if (!array_key_exists($key, $this->data)) {
            return $this;
        }

        if (!is_string($this->data[$key]) || $this->data[$key] === '') {
            $this->errors[] = ConfigValidationError::custom(
                $this->qualifiedKey($key),
                sprintf('Configuration key "%s" must be a non-empty string.', $this->qualifiedKey($key)),
            );
        }

        return $this;
    }

    /**
     * Add a custom validation error.
     */
    public function addError(string $key, string $message, ConfigSeverity $severity = ConfigSeverity::Error): self
    {
        $this->errors[] = ConfigValidationError::custom($this->qualifiedKey($key), $message, $severity);

        return $this;
    }

    /**
     * Get the validation result.
     */
    #[NoDiscard]
    public function result(): ConfigValidationResult
    {
        return new ConfigValidationResult($this->errors);
    }

    /**
     * Check if there are validation errors.
     */
    #[NoDiscard]
    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    private function qualifiedKey(string $key): string
    {
        return $this->context . '.' . $key;
    }
}
