<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\BulkAction;

use Pulsar\Audit\MutationContext;

/**
 * Request DTO for a bulk action on resource records.
 */
final readonly class BulkActionRequest
{
    /**
     * @param list<string> $ids
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public string $resourceName,
        public string $action,
        public array $ids,
        public array $parameters,
        public MutationContext $context,
    ) {}
}
