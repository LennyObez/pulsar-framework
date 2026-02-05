<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\ViewResource;

/**
 * Request DTO for viewing a single resource record.
 */
final readonly class ViewResourceRequest
{
    public function __construct(
        public string $resourceName,
        public string $id,
    ) {}
}
