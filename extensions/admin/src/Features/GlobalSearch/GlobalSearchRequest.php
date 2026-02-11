<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\GlobalSearch;

/**
 * Request DTO for global search across admin resources.
 */
final readonly class GlobalSearchRequest
{
    public function __construct(
        public string $query,
        public int $limitPerResource = 5,
    ) {}
}
