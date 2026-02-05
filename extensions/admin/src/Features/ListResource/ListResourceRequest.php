<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\ListResource;

/**
 * Request DTO for listing resource records.
 */
final readonly class ListResourceRequest
{
    /**
     * @param array<string, mixed> $filters
     * @param array<string, string> $sort
     */
    public function __construct(
        public string $resourceName,
        public array $filters = [],
        public array $sort = [],
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}
