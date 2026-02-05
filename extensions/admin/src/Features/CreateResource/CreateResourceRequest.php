<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\CreateResource;

use Pulsar\Audit\MutationContext;

/**
 * Request DTO for creating a resource record.
 */
final readonly class CreateResourceRequest
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $resourceName,
        public array $data,
        public MutationContext $context,
    ) {}
}
