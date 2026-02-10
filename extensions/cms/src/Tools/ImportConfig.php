<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tools;

use Pulsar\Api\Api;

/**
 * Configuration for CMS import operations.
 */
#[Api(since: '1.0.0')]
final readonly class ImportConfig
{
    private const int DEFAULT_MAX_IMPORT_SIZE = 50 * 1024 * 1024; // 50 MB

    /**
     * @param int $maxImportSizeBytes Maximum allowed import file size in bytes
     * @param bool $allowExternalMediaDownload Whether to download media from external URLs
     * @param bool $dryRunDefault Whether imports default to dry-run mode
     */
    public function __construct(
        public int $maxImportSizeBytes = self::DEFAULT_MAX_IMPORT_SIZE,
        public bool $allowExternalMediaDownload = true,
        public bool $dryRunDefault = true,
    ) {}

    /**
     * @param array{
     *     max_import_size_bytes?: int,
     *     allow_external_media_download?: bool,
     *     dry_run_default?: bool,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            maxImportSizeBytes: $data['max_import_size_bytes'] ?? self::DEFAULT_MAX_IMPORT_SIZE,
            allowExternalMediaDownload: $data['allow_external_media_download'] ?? true,
            dryRunDefault: $data['dry_run_default'] ?? true,
        );
    }
}
