<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\ExportResource;

/**
 * Result DTO for a resource data export.
 */
final readonly class ExportResourceResult
{
    public function __construct(
        public string $content,
        public string $mimeType,
        public string $filename,
        public string $evidenceHash,
        public int $rowCount,
    ) {}
}
