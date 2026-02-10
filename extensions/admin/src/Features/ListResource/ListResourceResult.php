<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\ListResource;

/**
 * Result DTO for listing resource records.
 */
final readonly class ListResourceResult
{
    /**
     * @param list<array<string, mixed>> $data
     */
    public function __construct(
        public array $data,
        public int $total,
        public int $page,
        public int $perPage,
        public int $totalPages,
    ) {}
}
