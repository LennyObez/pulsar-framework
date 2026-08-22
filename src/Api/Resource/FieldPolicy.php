<?php

declare(strict_types=1);

namespace Pulsar\Api\Resource;

use Pulsar\Api\Api;
use Pulsar\Security\Compliance\DataClassification;

/**
 * Resolved policy for a single exposed field.
 *
 * Computed from the {@see \Pulsar\Api\Resource\Attribute\Expose} and
 * {@see \Pulsar\Api\Resource\Attribute\ClassificationTag} attributes during
 * resource metadata resolution. Immutable for the request lifetime.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FieldPolicy
{
    /**
     * @param string $name The field name as it appears in the serialized output
     * @param string $propertyName The original property name on the resource class
     * @param DataClassification $classification Data classification level
     * @param list<string> $requiredPermissions Permissions required to see this field
     * @param list<string> $requiredRoles Roles required to see this field (any match)
     * @param bool $filterable Whether the field supports filtering
     * @param list<string> $filterOperators Allowed filter operators if filterable
     * @param bool $sortable Whether the field supports sorting
     */
    public function __construct(
        public string $name,
        public string $propertyName,
        public DataClassification $classification = DataClassification::Public,
        public array $requiredPermissions = [],
        public array $requiredRoles = [],
        public bool $filterable = false,
        public array $filterOperators = [],
        public bool $sortable = false,
    ) {}

    /**
     * Whether this field requires authorization checks.
     */
    public function requiresAuthorization(): bool
    {
        return $this->requiredPermissions !== [] || $this->requiredRoles !== [];
    }
}
