<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;

use function is_array;
use function is_bool;
use function is_int;
use function is_string;

/**
 * Theme system configuration.
 *
 * @psalm-api Public configuration DTO loaded from config/cms.php; consumed
 *            by ThemeManager, SafeArchiveExtractor, and theme rendering.
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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            storagePath: is_string($data['storage_path'] ?? null) ? $data['storage_path'] : 'storage/cms/themes',
            assetDeployMode: is_string($data['asset_deploy_mode'] ?? null) ? $data['asset_deploy_mode'] : 'copy',
            requireSignedThemes: is_bool($data['require_signed_themes'] ?? null) ? $data['require_signed_themes'] : true,
            trustedPublicKeys: is_array($data['trusted_public_keys'] ?? null) ? array_values(array_map(static fn(mixed $v): string => is_string($v) ? $v : '', $data['trusted_public_keys'])) : [],
            integrityCheckOnBoot: is_bool($data['integrity_check_on_boot'] ?? null) ? $data['integrity_check_on_boot'] : true,
            maxArchiveSize: is_int($data['max_archive_size'] ?? null) ? $data['max_archive_size'] : 52_428_800,
            maxFileCount: is_int($data['max_file_count'] ?? null) ? $data['max_file_count'] : 10_000,
        );
    }
}
