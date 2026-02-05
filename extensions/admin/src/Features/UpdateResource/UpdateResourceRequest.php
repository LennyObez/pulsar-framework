<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\UpdateResource;

use Pulsar\Audit\MutationContext;

/**
 * Request DTO for updating a resource record.
 */
final readonly class UpdateResourceRequest
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $resourceName,
        public string $id,
        public array $data,
        public MutationContext $context,
    ) {}
}
