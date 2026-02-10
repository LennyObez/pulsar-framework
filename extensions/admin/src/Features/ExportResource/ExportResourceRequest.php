<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\ExportResource;

use Pulsar\Extension\Admin\Domain\ExportFormat;

/**
 * Request DTO for exporting resource data.
 */
final readonly class ExportResourceRequest
{
    /**
     * @param array<string, mixed> $filters
     */
    public function __construct(
        public string $resourceName,
        public ExportFormat $format,
        public array $filters = [],
        public int $maxRows = 10000,
    ) {}
}
