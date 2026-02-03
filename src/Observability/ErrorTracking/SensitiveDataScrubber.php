<?php

declare(strict_types=1);

namespace Pulsar\Observability\ErrorTracking;

use function array_any;
use function array_merge;
use function in_array;
use function is_array;
use function str_contains;
use function strtolower;

/**
 * Recursively scrubs sensitive data from arrays and headers.
 *
 * Uses case-insensitive substring matching against a configurable list
 * of sensitive field names.
 */
final readonly class SensitiveDataScrubber
{
    private const string REDACTED = '[REDACTED]';

    /** @var list<string> Default sensitive field substrings */
    private const array DEFAULT_FIELDS = [
        'password',
        'token',
        'secret',
        'api_key',
        'authorization',
        'credential',
        'credit_card',
        'ssn',
        'social_security',
        'private_key',
    ];

    /** @var list<string> Headers that are always scrubbed */
    private const array SENSITIVE_HEADERS = [
        'authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
    ];

    /** @var list<string> */
    private array $fields;

    /**
     * @param list<string> $customFields Additional sensitive field substrings
     */
    public function __construct(array $customFields = [])
    {
        $this->fields = array_merge(self::DEFAULT_FIELDS, $customFields);
    }

    /**
     * Recursively scrub sensitive values from an array.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function scrub(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                $result[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $value */
                $result[$key] = $this->scrub($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Scrub sensitive headers.
     *
     * @param array<string, string|list<string>> $headers
     *
     * @return array<string, string|list<string>>
     */
    public function scrubHeaders(array $headers): array
    {
        $result = [];

        foreach ($headers as $name => $value) {
            if ($this->isSensitiveHeader($name)) {
                $result[$name] = self::REDACTED;
            } else {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);

        return array_any(
            $this->fields,
            static fn(string $field): bool => str_contains($lower, $field),
        );
    }

    private function isSensitiveHeader(string $name): bool
    {
        $lower = strtolower($name);

        if (in_array($lower, self::SENSITIVE_HEADERS, true)) {
            return true;
        }

        // Also check general sensitive fields
        return $this->isSensitiveKey($name);
    }
}
