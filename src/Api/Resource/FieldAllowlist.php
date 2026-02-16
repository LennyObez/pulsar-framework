<?php

declare(strict_types=1);

namespace Pulsar\Api\Resource;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Api\Exception\ApiException;

use function array_diff;
use function array_keys;
use function count;
use function implode;
use function in_array;

/**
 * Validates sparse fieldset requests (`?fields=`) against declared exposed fields.
 *
 * Ensures that only fields explicitly marked with {@see Attribute\Expose} can be
 * requested. Unknown fields produce a 400 Bad Request response.
 */
#[Api(since: '1.0.0')]
final readonly class FieldAllowlist
{
    /**
     * @var array<string, FieldPolicy> Field name => policy mapping
     */
    private array $policies;

    /**
     * @param array<string, FieldPolicy> $fieldPolicies Map of exposed field name => policy
     * @param string $resourceType Resource type identifier for error messages
     * @param int $maxFields Maximum number of fields allowed per request
     */
    public function __construct(
        array $fieldPolicies,
        private string $resourceType,
        private int $maxFields,
    ) {
        $this->policies = $fieldPolicies;
    }

    /**
     * Validate a list of requested field names against the allowlist.
     *
     * @param list<string> $requestedFields Fields from the `?fields=` query parameter
     *
     * @throws ApiException If any field is unknown or the field count exceeds the limit
     *
     * @return list<string> The validated field names
     */
    #[NoDiscard]
    public function validate(array $requestedFields): array
    {
        $count = count($requestedFields);

        if ($count > $this->maxFields) {
            throw ApiException::fieldLimitExceeded($count, $this->maxFields);
        }

        $allowedNames = array_keys($this->policies);
        $unknown = array_diff($requestedFields, $allowedNames);

        if ($unknown !== []) {
            throw ApiException::unknownField(
                implode(', ', $unknown),
                $this->resourceType,
            );
        }

        return $requestedFields;
    }

    /**
     * Get the default field set (all exposed field names).
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function defaultFields(): array
    {
        return array_keys($this->policies);
    }

    /**
     * Check if a field name is in the allowlist.
     */
    public function has(string $fieldName): bool
    {
        return isset($this->policies[$fieldName]);
    }

    /**
     * Get the policy for a specific field.
     */
    #[NoDiscard]
    public function policy(string $fieldName): ?FieldPolicy
    {
        return $this->policies[$fieldName] ?? null;
    }

    /**
     * Get all field policies.
     *
     * @return array<string, FieldPolicy>
     */
    #[NoDiscard]
    public function allPolicies(): array
    {
        return $this->policies;
    }

    /**
     * Filter the requested fields to only those that are in the allowlist.
     * Unlike validate(), this does not throw on unknown fields; it silently ignores them.
     *
     * @param list<string> $requestedFields
     * @return list<string>
     */
    #[NoDiscard]
    public function filter(array $requestedFields): array
    {
        $allowedNames = array_keys($this->policies);
        $filtered = [];

        foreach ($requestedFields as $field) {
            if (in_array($field, $allowedNames, true)) {
                $filtered[] = $field;
            }
        }

        return $filtered;
    }
}
