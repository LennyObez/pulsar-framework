<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tools;

use Pulsar\Api\Api;

/**
 * Exports CMS data as a ZIP bundle including media files.
 *
 * @psalm-api Public binding contract; implemented by MediaBundleExporter and
 *            consumed by admin export controllers.
 * @api
 */
#[Api(since: '1.0.0')]
interface MediaBundleExporterInterface
{
    /**
     * Export CMS data and media files as a ZIP archive.
     *
     * The returned ZIP contains:
     *  - manifest.json with schema version, timestamp, entity counts, and BLAKE2b evidence hash
     *  - data.json with the export bundle data
     *  - media/ directory with actual media files
     *
     * @return string Absolute path to the temporary ZIP file
     */
    public function exportZip(ExportOptions $options): string;
}
