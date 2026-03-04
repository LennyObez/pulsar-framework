<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Themes;

use DateTimeImmutable;
use JsonException;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Themes\InstalledTheme;
use Pulsar\Extension\Cms\Themes\ThemeManifest;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;

use function bin2hex;
use function dirname;
use function file_exists;
use function file_get_contents;
use function glob;
use function hash_file;
use function is_array;
use function json_decode;
use function random_bytes;
use function sprintf;

use const GLOB_NOSORT;
use const JSON_THROW_ON_ERROR;

/**
 * Discovers themes on disk by scanning `resources/themes/` * /theme.json
 * and auto-registers any that are not yet present in the database.
 *
 * Called during CMS extension boot to keep the installed themes table
 * synchronized with the filesystem. Existing themes (matched by slug)
 * are left untouched.
 */
#[Internal(reason: 'CMS internal; theme auto-discovery')]
final readonly class ThemeDiscoveryService
{
    public function __construct(
        private ThemeRepositoryInterface $repository,
        private LoggerInterface $logger,
        private string $themesBasePath,
    ) {}

    /**
     * Scan the themes directory and register any unregistered themes.
     *
     * @return list<InstalledTheme> Newly registered themes
     */
    public function discover(): array
    {
        $pattern = $this->themesBasePath . '/*/theme.json';
        $manifestPaths = glob($pattern, GLOB_NOSORT);

        if ($manifestPaths === false || $manifestPaths === []) {
            return [];
        }

        $registered = [];

        foreach ($manifestPaths as $manifestPath) {
            $theme = $this->processManifest($manifestPath);

            if ($theme !== null) {
                $registered[] = $theme;
            }
        }

        return $registered;
    }

    /**
     * Process a single theme.json manifest and register if not already known.
     */
    private function processManifest(string $manifestPath): ?InstalledTheme
    {
        if (!file_exists($manifestPath)) {
            return null;
        }

        $contents = file_get_contents($manifestPath);

        if ($contents === false || $contents === '') {
            $this->logger->warning(sprintf('Empty or unreadable theme manifest: %s', $manifestPath));

            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->warning(sprintf(
                'Invalid JSON in theme manifest %s: %s',
                $manifestPath,
                $e->getMessage(),
            ));

            return null;
        }

        if (!is_array($decoded)) {
            $this->logger->warning(sprintf('Theme manifest is not a JSON object: %s', $manifestPath));

            return null;
        }

        /** @var array<string, mixed> $manifestData */
        $manifestData = $decoded;
        $manifest = ThemeManifest::fromArray($manifestData);

        if ($manifest->slug === '') {
            $this->logger->warning(sprintf('Theme manifest missing slug: %s', $manifestPath));

            return null;
        }

        // Skip if already registered
        $existing = $this->repository->findBySlug($manifest->slug);

        if ($existing !== null) {
            return null;
        }

        // Determine the storage path (parent directory of theme.json)
        $storagePath = dirname($manifestPath);

        $manifestHash = hash_file('sha256', $manifestPath);

        if ($manifestHash === false) {
            $this->logger->warning(sprintf('Failed to hash theme manifest: %s', $manifestPath));

            return null;
        }

        $now = new DateTimeImmutable();
        $themeId = bin2hex(random_bytes(16));

        $theme = new InstalledTheme(
            id: $themeId,
            tenantId: null,
            slug: $manifest->slug,
            displayName: $manifest->displayName,
            version: $manifest->version,
            description: $manifest->description,
            authorName: $manifest->authorName,
            authorUrl: $manifest->authorUrl,
            license: $manifest->license,
            manifestHash: $manifestHash,
            packageHash: $manifestHash,
            provenanceVerified: false,
            signatureVerified: false,
            isActive: false,
            storagePath: $storagePath,
            installedAt: $now,
            installedBy: 'auto-discovery',
            activatedAt: null,
            activatedBy: null,
            deactivatedAt: null,
            deletedAt: null,
        );

        $this->repository->save($theme);

        $this->logger->info(sprintf(
            'Auto-discovered and registered theme "%s" (v%s) from %s',
            $manifest->displayName,
            $manifest->version,
            $storagePath,
        ));

        return $theme;
    }
}
