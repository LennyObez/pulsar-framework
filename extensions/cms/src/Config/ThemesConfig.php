<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
        return new self(
            storagePath: Coerce::string($data['storage_path'] ?? null, 'storage/cms/themes'),
            assetDeployMode: Coerce::string($data['asset_deploy_mode'] ?? null, 'copy'),
            requireSignedThemes: Coerce::strictBool($data['require_signed_themes'] ?? null, true),
            trustedPublicKeys: Coerce::stringListOrEmpty($data['trusted_public_keys'] ?? null),
            integrityCheckOnBoot: Coerce::strictBool($data['integrity_check_on_boot'] ?? null, true),
            maxArchiveSize: Coerce::strictInt($data['max_archive_size'] ?? null, 52_428_800),
            maxFileCount: Coerce::strictInt($data['max_file_count'] ?? null, 10_000),
        );
    }
}
