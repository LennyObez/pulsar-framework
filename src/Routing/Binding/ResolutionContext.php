<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use Pulsar\Api\Api;

/**
 * Context passed to model resolvers during route parameter binding.
 *
 * Carries tenant scope, subject identity, soft-delete inclusion, and
 * arbitrary attributes so resolvers can apply appropriate filtering.
 */
#[Api(since: '1.0.0-rc.11')]
readonly class ResolutionContext
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public ?string $tenantId = null,
        public ?string $subjectId = null,
        public bool $includeTrashed = false,
        public array $attributes = [],
    ) {}
}
