<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Domain;

use Pulsar\Api\Api;

/**
 * Persisted filter/sort preset for an admin resource list.
 */
#[Api(since: '1.0.0')]
final readonly class SavedView
{
    /**
     * @param array<string, mixed> $filters
     * @param array<string, string> $sort
     */
    public function __construct(
        public string $id,
        public string $resourceName,
        public string $label,
        public array $filters,
        public array $sort,
        public int $perPage,
        public string $createdBy,
        public bool $isDefault = false,
        public int $createdAt = 0,
    ) {}
}
