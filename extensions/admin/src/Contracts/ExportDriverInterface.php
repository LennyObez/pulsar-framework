<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Admin\Domain\ExportFormat;

/**
 * Contract for export format drivers.
 */
#[Api(since: '1.0.0')]
interface ExportDriverInterface
{
    /**
     * The export format this driver handles.
     */
    public function format(): ExportFormat;

    /**
     * Get the MIME type for the exported file.
     */
    public function mimeType(): string;

    /**
     * Get the file extension.
     */
    public function fileExtension(): string;

    /**
     * Export data rows into a string output.
     *
     * @param list<string> $columns Column names/headers
     * @param list<array<string, scalar|null>> $rows Data rows with scalar-only values
     * @return string The serialized export output
     */
    public function export(array $columns, array $rows): string;
}
