<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tools;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function array_key_exists;

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
        $allowedLocales = array_key_exists('allowed_locales', $data)
            ? Coerce::listOfString($data['allowed_locales'])
            : null;

        return new self(
            maxImportSizeBytes: Coerce::int($data['max_import_size_bytes'] ?? null, self::DEFAULT_MAX_IMPORT_SIZE),
            allowExternalMediaDownload: Coerce::strictBool($data['allow_external_media_download'] ?? null, true),
            dryRunDefault: Coerce::strictBool($data['dry_run_default'] ?? null, true),
            duplicatePolicy: DuplicateResolutionPolicy::tryFrom(Coerce::string($data['duplicate_policy'] ?? null))
                ?? DuplicateResolutionPolicy::Skip,
            allowedLocales: $allowedLocales,
        );
    }
}
