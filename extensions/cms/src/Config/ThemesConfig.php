<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;

use function array_values;

/**
 * Theme system configuration.
 *
 * @psalm-api Public configuration DTO loaded from config/cms.php; consumed
 *            by ThemeManager, SafeArchiveExtractor, and theme rendering.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ThemesConfig
{
    /**
     * @param string $storagePath Base storage path for installed themes
     * @param string $assetDeployMode How theme assets are deployed ('copy' or 'symlink')
     * @param bool $requireSignedThemes Whether theme packages must have valid signatures
     * @param list<string> $trustedPublicKeys Ed25519 public keys trusted for theme signature verification
     * @param bool $integrityCheckOnBoot Verify theme file integrity on application boot
     * @param int $maxArchiveSize Maximum theme archive size in bytes (default: 50 MB)
     * @param int $maxFileCount Maximum number of files allowed in a theme archive
     */
    public function __construct(
        public string $storagePath = 'storage/cms/themes',
        public string $assetDeployMode = 'copy',
        public bool $requireSignedThemes = true,
        public array $trustedPublicKeys = [],
        public bool $integrityCheckOnBoot = true,
        public int $maxArchiveSize = 52_428_800,
        public int $maxFileCount = 10_000,
    ) {}

    /**
     * @param array{
     *     storage_path?: string,
     *     asset_deploy_mode?: string,
     *     require_signed_themes?: bool,
     *     trusted_public_keys?: list<string>,
     *     integrity_check_on_boot?: bool,
     *     max_archive_size?: int,
     *     max_file_count?: int,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $trustedPublicKeys = array_values($data['trusted_public_keys'] ?? []);

        return new self(
            storagePath: $data['storage_path'] ?? 'storage/cms/themes',
            assetDeployMode: $data['asset_deploy_mode'] ?? 'copy',
            requireSignedThemes: $data['require_signed_themes'] ?? true,
            trustedPublicKeys: $trustedPublicKeys,
            integrityCheckOnBoot: $data['integrity_check_on_boot'] ?? true,
            maxArchiveSize: $data['max_archive_size'] ?? 52_428_800,
            maxFileCount: $data['max_file_count'] ?? 10_000,
        );
    }
}
