<?php

declare(strict_types=1);

namespace Pulsar\Auth\Authorization;

use Pulsar\Api\Api;

/**
 * Context object passed to ABAC policies during evaluation.
 */
#[Api]
readonly class PolicyContext
{
    /**
     * @param array<string, mixed> $attributes Additional context attributes
     */
    public function __construct(
        public string $permission,
        public ?string $resource = null,
        public array $attributes = [],
    ) {}
}
