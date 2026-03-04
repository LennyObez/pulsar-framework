<?php

declare(strict_types=1);

namespace Pulsar\ImportExport;

use Pulsar\Api\Api;

/**
 * Extension-aware import/export provider.
 *
 * Each active extension can implement this interface to register
 * its own import/export capabilities with the central registry.
 * The registry aggregates all providers and routes import/export
 * operations to the appropriate extension.
 */
#[Api(since: '1.0.0')]
interface ImportExportProviderInterface
{
    /**
     * Unique provider name (e.g., 'cms', 'forum', 'payments').
     */
    public function name(): string;

    /**
     * Human-readable label for admin UI display.
     */
    public function label(): string;

    /**
     * Supported file formats for import/export.
     *
     * @return list<string> e.g., ['json', 'csv', 'xml']
     */
    public function supportedFormats(): array;

    /**
     * Export data according to the given request.
     */
    public function export(ExportRequest $request): ExportResult;

    /**
     * Import data according to the given request.
     */
    public function import(ImportRequest $request): ImportResult;

    /**
     * Describe the importable/exportable data structure.
     *
     * Returns a schema map keyed by entity type name, with each value
     * describing the fields and their types for documentation and validation.
     *
     * @return array<string, array<string, string>> Entity type => field => type description
     */
    public function schema(): array;
}
