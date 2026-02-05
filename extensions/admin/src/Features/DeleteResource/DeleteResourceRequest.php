<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\DeleteResource;

use Pulsar\Audit\MutationContext;

/**
 * Request DTO for deleting a resource record.
 */
final readonly class DeleteResourceRequest
{
    public function __construct(
        public string $resourceName,
        public string $id,
        public MutationContext $context,
    ) {}
}
