<?php

declare(strict_types=1);

namespace Pulsar\Api\Sort;

use Pulsar\Api\Api;

/**
 * Defines a single allowed sort field on a resource.
 */
#[Api(since: '1.0.0')]
final readonly class SortDefinition
{
    /**
     * @param string $column The safe query builder column name
     * @param string|null $guard Role or permission required to sort by this field
     */
    public function __construct(
        public string $column,
        public ?string $guard = null,
    ) {}

    /**
     * Check if this sort requires authorization.
     */
    public function requiresAuthorization(): bool
    {
        return $this->guard !== null;
    }
}
