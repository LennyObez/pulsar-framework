<?php

declare(strict_types=1);

namespace Pulsar\Build;

use Pulsar\Api\Internal;
use Pulsar\Extension\Compiler\CompiledExtensionManifest;
use Pulsar\Extension\Compiler\ManifestLoader;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Crypto\KeyProviderInterface;

use function file_get_contents;
use function getenv;
use function is_dir;
use function is_file;

use const DIRECTORY_SEPARATOR;

/**
 * Loads compiled build artifacts in production mode.
 *
 * Integrates with the Kernel boot process to provide artifact loading,
 * integrity verification, and staleness detection.
 */
#[Internal]
final class BuildArtifactLoader
{
    private const string MANIFEST_FILE = 'build-manifest.json';
    private const string EXTENSIONS_FILE = 'extensions.manifest.php';
    private const string EVENTS_MAP_FILE = 'events_map.php';
    private const string I18N_INDEX_FILE = 'i18n_catalog_index.php';

    public function __construct(
        private readonly string $cacheDir,
    ) {}

    /**
     * Check if build artifacts exist (build manifest present).
     */
    public function hasArtifacts(): bool
    {
        return is_file($this->cacheDir . DIRECTORY_SEPARATOR . self::MANIFEST_FILE);
    }

    /**
     * Check if the cache directory exists.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function cacheExists(): bool
    {
        return is_dir($this->cacheDir);
    }

    /**
     * Load the build manifest.
     */
    public function loadManifest(): ?BuildManifest
    {
        $path = $this->cacheDir . DIRECTORY_SEPARATOR . self::MANIFEST_FILE;

        if (!is_file($path)) {
            return null;
        }

        $json = file_get_contents($path);

        if ($json === false) {
            return null;
        }

        return BuildManifest::fromJson($json);
    }

    /**
     * Verify artifact integrity against the manifest.
     *
     * Returns null if no manifest exists.
     */
    public function verifyIntegrity(?BuildManifest $manifest = null): ?VerificationResult
    {
        $manifest ??= $this->loadManifest();

        if ($manifest === null) {
            return null;
        }

        $verifier = new ArtifactIntegrityVerifier();

        return $verifier->verify($manifest, $this->cacheDir);
    }

    /**
     * Verify manifest signature if present.
     */
    public function verifySignature(
        BuildManifest $manifest,
        HmacInterface $hmac,
        KeyProviderInterface $keyProvider,
    ): bool {
        if ($manifest->signature === null) {
            return true; // No signature = no requirement
        }

        $verifier = new ArtifactIntegrityVerifier();

        return $verifier->verifySignature($manifest, $hmac, $keyProvider);
    }

    /**
     * Load compiled extension manifest.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function loadExtensionManifest(): ?CompiledExtensionManifest
    {
        $path = $this->cacheDir . DIRECTORY_SEPARATOR . self::EXTENSIONS_FILE;
        $loader = new ManifestLoader();

        if (!$loader->exists($path)) {
            return null;
        }

        return $loader->load($path);
    }

    /**
     * Load compiled event listener map.
     *
     * @return array<class-string, array{listeners: list<array{class: string, method: string, priority: int, moduleId: string}>, requiresEnvelope: bool, stormOverride: ?int, listenerModuleIds: list<string>}>|null
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function loadEventMap(): ?array
    {
        $path = $this->cacheDir . DIRECTORY_SEPARATOR . self::EVENTS_MAP_FILE;

        if (!is_file($path)) {
            return null;
        }

        /** @var array<class-string, array{listeners: list<array{class: string, method: string, priority: int, moduleId: string}>, requiresEnvelope: bool, stormOverride: ?int, listenerModuleIds: list<string>}> */
        return require $path;
    }

    /**
     * Load compiled i18n catalog index.
     *
     * @return array<string, mixed>|null
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function loadI18nCatalogIndex(): ?array
    {
        $path = $this->cacheDir . DIRECTORY_SEPARATOR . self::I18N_INDEX_FILE;

        if (!is_file($path)) {
            return null;
        }

        /** @var array<string, mixed> */
        return require $path;
    }

    /**
     * Determine if artifact verification is enabled.
     *
     * Checks the PULSAR_VERIFY_ARTIFACTS environment variable.
     */
    public static function isVerificationEnabled(): bool
    {
        $value = getenv('PULSAR_VERIFY_ARTIFACTS');

        return $value === '1' || $value === 'true';
    }

    /**
     * Get the list of required artifact keys for production boot.
     *
     * @return list<string>
     */
    public static function requiredArtifacts(): array
    {
        return ['config', 'routes'];
    }

    /**
     * Check if the required artifacts for production boot exist in the manifest.
     *
     * @param BuildManifest $manifest
     * @return list<string> Missing required artifact keys
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function checkRequiredArtifacts(BuildManifest $manifest): array
    {
        $missing = [];

        foreach (self::requiredArtifacts() as $key) {
            if (!isset($manifest->artifacts[$key])) {
                $missing[] = $key;
            }
        }

        return $missing;
    }
}
