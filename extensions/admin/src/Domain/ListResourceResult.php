<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Domain;

use Pulsar\Api\Api;

/**
 * Result DTO for listing resource records.
 */
#[Api(since: '1.0.0')]
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
