<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tools;

use Pulsar\Api\Api;

/**
 * Service interface for CMS data import and export operations.
 *
 * Supports structured bundle export/import (selective entity types)
 * and full-site definition import (N.3 schema).
 *
 * @psalm-api Public binding contract; implemented by ImportExportService and
 *            consumed by admin import/export controllers and the framework
 *            ImportExportProvider adapter.
 */
#[Api(since: '1.0.0')]
interface ImportExportServiceInterface
{
    /**
     * Export CMS data as a bundle based on the given options.
     *
     * Generates a portable JSON-serializable bundle with an evidence hash
     * for integrity verification. PII fields are redacted unless explicitly
     * requested via ExportOptions::$includePii.
     */
    public function exportBundle(ExportOptions $options): ExportBundle;

    /**
     * Import entities from a previously exported JSON bundle.
     *
     * In dry-run mode, validates and counts entities without persisting.
     * In execute mode, creates or updates entities as needed.
     */
    public function importBundle(string $jsonContent, bool $dryRun = true): ImportResult;

    /**
     * Import a full site definition conforming to the N.3 schema.
     *
     * Processes taxonomies, media, content, menus, settings, redirects, and SEO
     * configuration in dependency order. Media assets with source URLs are
     * downloaded via the SSRF-safe HTTP client.
     */
    public function importSiteDefinition(string $jsonContent, bool $dryRun = true): ImportResult;

    /**
     * Import a unified JSON file with auto-detection of content type.
     *
     * Inspects the root keys of the JSON document to determine what sections
     * are present, then routes each section to the appropriate handler:
     *   - `site` key → full SiteDefinition import
     *   - `content` key → import pages/articles via bundle importer
     *   - `taxonomies` key → import taxonomies via bundle importer
     *   - `menus` key → import menus via bundle importer
     *   - `forum` key → delegate to ForumImportExportProvider via registry
     *   - `booking` key → delegate to BookingImportExportProvider via registry
     *   - `analytics` key → delegate to AnalyticsImportExportProvider via registry
     *   - `providers` key → ImportExportProvider format (multi-provider bundle)
     *
     * Used by both CLI (`pulsar cms:import`) and the admin GUI import endpoint.
     */
    public function importUnifiedFile(string $jsonContent, bool $dryRun = true): ImportResult;
}
