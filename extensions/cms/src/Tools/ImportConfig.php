<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tools;

use Pulsar\Api\Api;

use function array_filter;
use function array_values;

/**
 * Configuration for CMS import operations.
 *
 * @psalm-api Public configuration DTO loaded from config/cms.php as part of
 *            CmsConfig.import; consumed by ImportExportService.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ImportConfig
{
    private const int DEFAULT_MAX_IMPORT_SIZE = 50 * 1024 * 1024; // 50 MB

    /**
     * @param int $maxImportSizeBytes Maximum allowed import file size in bytes
     * @param bool $allowExternalMediaDownload Whether to download media from external URLs
     * @param bool $dryRunDefault Whether imports default to dry-run mode
     * @param DuplicateResolutionPolicy $duplicatePolicy Strategy for resolving duplicate entities
     * @param list<string>|null $allowedLocales Only import content in these locales; null = all
     */
    public function __construct(
        public int $maxImportSizeBytes = self::DEFAULT_MAX_IMPORT_SIZE,
        public bool $allowExternalMediaDownload = true,
        public bool $dryRunDefault = true,
        public DuplicateResolutionPolicy $duplicatePolicy = DuplicateResolutionPolicy::Skip,
        public ?array $allowedLocales = null,
    ) {}

    /**
     * @param array{
     *     max_import_size_bytes?: int,
     *     allow_external_media_download?: bool,
     *     dry_run_default?: bool,
     *     duplicate_policy?: string,
     *     allowed_locales?: list<string>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $allowedLocales = isset($data['allowed_locales'])
            ? array_values(array_filter($data['allowed_locales'], 'is_string'))
            : null;

        return new self(
            maxImportSizeBytes: $data['max_import_size_bytes'] ?? self::DEFAULT_MAX_IMPORT_SIZE,
            allowExternalMediaDownload: $data['allow_external_media_download'] ?? true,
            dryRunDefault: $data['dry_run_default'] ?? true,
            duplicatePolicy: DuplicateResolutionPolicy::tryFrom($data['duplicate_policy'] ?? '')
                ?? DuplicateResolutionPolicy::Skip,
            allowedLocales: $allowedLocales,
        );
    }
}
