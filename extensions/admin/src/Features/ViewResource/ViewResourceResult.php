<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\ViewResource;

/**
 * Result DTO for viewing a single resource record.
 */
final readonly class ViewResourceResult
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public array $data,
    ) {}
}
