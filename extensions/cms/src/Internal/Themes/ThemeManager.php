<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Themes;

use DateTimeImmutable;
use FilesystemIterator;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\Config\ThemesConfig;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Support\UuidGenerator;
use Pulsar\Extension\Cms\Themes\Event\ThemeActivated;
use Pulsar\Extension\Cms\Themes\Event\ThemeDeleted;
use Pulsar\Extension\Cms\Themes\Event\ThemeInstalled;
use Pulsar\Extension\Cms\Themes\InstalledTheme;
use Pulsar\Extension\Cms\Themes\PreviewSession;
use Pulsar\Extension\Cms\Themes\PreviewSessionRepositoryInterface;
use Pulsar\Extension\Cms\Themes\ThemeArchiveExtractorInterface;
use Pulsar\Extension\Cms\Themes\ThemeManagerInterface;
use Pulsar\Extension\Cms\Themes\ThemeManifest;
use Pulsar\Extension\Cms\Themes\ThemeManifestValidatorInterface;
use Pulsar\Extension\Cms\Themes\ThemeProvenanceVerifierInterface;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function bin2hex;
use function dirname;
use function file_exists;
use function file_get_contents;
use function hash;
use function hash_file;
use function implode;
use function in_array;
use function is_dir;
use function json_decode;
use function random_bytes;
use function realpath;
use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;

use const JSON_THROW_ON_ERROR;

/**
 * Theme lifecycle manager handling install, activate, deactivate, delete, preview, and rollback.
 *
 * @psalm-api Bound to ThemeManagerInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
 */
#[Internal(reason: 'Use ThemeManagerInterface for public API')]
final readonly class ThemeManager implements ThemeManagerInterface
{
    /** Allowed asset file extensions for deployment to public directory. */
    private const array SAFE_ASSET_EXTENSIONS = [
        'css', 'js', 'woff', 'woff2', 'ttf', 'eot',
        'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'map',
    ];

    public function __construct(
        private ThemeRepositoryInterface $repository,
        private ThemeManifestValidatorInterface $manifestValidator,
        private ThemeProvenanceVerifierInterface $provenanceVerifier,
        private ThemeArchiveExtractorInterface $archiveExtractor,
        private ThemesConfig $config,
        private EventDispatcherInterface $eventDispatcher,
        private ?AuditLoggerInterface $auditLogger,
        private LoggerInterface $logger,
        private PreviewSessionRepositoryInterface $previewSessionRepository,
        private string $publicPath = 'public',
    ) {}

    public function install(string $archivePath, string $installedBy, ?string $tenantId = null): InstalledTheme
    {
        // Step 1: Verify provenance (SHA-256 + optional Ed25519 signature)
        $signaturePath = $archivePath . '.sig';
        $sigPath = file_exists($signaturePath) ? $signaturePath : null;

        $provenance = $this->provenanceVerifier->verify($archivePath, $sigPath);

        if (!$provenance->isAcceptable($this->config->requireSignedThemes)) {
            throw CmsException::themeProvenanceFailed(
                $provenance->error ?? 'Provenance verification failed',
            );
        }

        // Step 2: Extract to a temporary directory for manifest validation
        $packageHash = hash_file('sha256', $archivePath);

        if ($packageHash === false) {
            throw CmsException::themeExtractionFailed('Failed to compute archive hash');
        }

        $tempDir = sys_get_temp_dir() . '/pulsar_theme_' . bin2hex(random_bytes(8));
        $extractResult = $this->archiveExtractor->extract($archivePath, $tempDir);

        if (!$extractResult->success) {
            throw CmsException::themeExtractionFailed(implode('; ', $extractResult->errors));
        }

        try {
            // Step 3: Read and validate theme.json manifest
            $manifestPath = $tempDir . '/theme.json';

            if (!file_exists($manifestPath)) {
                throw CmsException::themeManifestInvalid('Missing theme.json in archive root');
            }

            $manifestJson = file_get_contents($manifestPath);

            if ($manifestJson === false) {
                throw CmsException::themeManifestInvalid('Cannot read theme.json');
            }

            /** @var array<string, mixed> $manifestData */
            $manifestData = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
            $manifest = ThemeManifest::fromArray($manifestData);

            $validation = $this->manifestValidator->validate($manifest);

            if (!$validation->isValid) {
                throw CmsException::themeManifestInvalid(implode('; ', $validation->errors));
            }

            // Step 4: Check for existing theme with same slug
            $existing = $this->repository->findBySlug($manifest->slug, $tenantId);

            if ($existing !== null) {
                throw CmsException::themeManifestInvalid(
                    "Theme with slug '$manifest->slug' is already installed",
                );
            }

            // Step 5: Move to permanent storage
            $storagePath = rtrim($this->config->storagePath, '/') . '/' . $manifest->slug;
            $this->moveDirectory($tempDir, $storagePath);

            // Step 6: Compute manifest hash
            $manifestHash = hash('sha256', $manifestJson);

            // Step 7: Create the installed theme record
            $themeId = UuidGenerator::v7();

            $theme = new InstalledTheme(
                id: $themeId,
                tenantId: $tenantId,
                slug: $manifest->slug,
                displayName: $manifest->displayName,
                version: $manifest->version,
                description: $manifest->description,
                authorName: $manifest->authorName,
                authorUrl: $manifest->authorUrl,
                license: $manifest->license,
                manifestHash: $manifestHash,
                packageHash: $packageHash,
                provenanceVerified: $provenance->hashValid,
                signatureVerified: $provenance->signatureValid,
                isActive: false,
                storagePath: $storagePath,
                installedAt: new DateTimeImmutable(),
                installedBy: $installedBy,
                activatedAt: null,
                activatedBy: null,
                deactivatedAt: null,
                deletedAt: null,
            );

            $this->repository->save($theme);

            $this->eventDispatcher->dispatch(new ThemeInstalled(
                themeId: $themeId,
                name: $manifest->displayName,
                version: $manifest->version,
                installedBy: $installedBy,
            ));

            $this->auditLogger?->log(
                AuditEvent::DataModification,
                AuditOutcome::Success,
                $installedBy,
                'cms.theme.installed',
                "theme:$themeId",
                ['slug' => $manifest->slug, 'version' => $manifest->version],
            );

            $this->logger->info('Theme installed', [
                'id' => $themeId,
                'slug' => $manifest->slug,
                'version' => $manifest->version,
            ]);

            return $theme;
        } finally {
            // Clean up temp directory if it still exists (move failed or exception)
            if (is_dir($tempDir)) {
                $this->removeDirectory($tempDir);
            }
        }
    }

    public function activate(string $themeId, string $activatedBy): InstalledTheme
    {
        $theme = $this->repository->findById($themeId);

        if ($theme === null) {
            throw CmsException::themeNotFound($themeId);
        }

        if ($theme->isActive) {
            throw CmsException::themeAlreadyActive($themeId);
        }

        // Deactivate currently active theme
        $currentActive = $this->repository->findActive($theme->tenantId);

        if ($currentActive !== null) {
            $deactivated = $currentActive->deactivate(new DateTimeImmutable());
            $this->repository->save($deactivated);
        }

        // Activate the new theme
        $now = new DateTimeImmutable();

        $activated = $theme->activate($activatedBy, $now);

        $this->repository->save($activated);

        // Deploy assets to public directory
        $this->deployAssets($activated);

        $this->eventDispatcher->dispatch(new ThemeActivated(
            themeId: $themeId,
            activatedBy: $activatedBy,
        ));

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $activatedBy,
            'cms.theme.activated',
            "theme:$themeId",
            ['slug' => $theme->slug],
        );

        $this->logger->info('Theme activated', [
            'id' => $themeId,
            'slug' => $theme->slug,
        ]);

        return $activated;
    }

    public function deactivate(string $themeId, string $deactivatedBy): InstalledTheme
    {
        $theme = $this->repository->findById($themeId);

        if ($theme === null) {
            throw CmsException::themeNotFound($themeId);
        }

        if (!$theme->isActive) {
            throw CmsException::themeNotActive($themeId);
        }

        $deactivated = $theme->deactivate(new DateTimeImmutable());

        $this->repository->save($deactivated);

        $this->logger->info('Theme deactivated', [
            'id' => $themeId,
            'slug' => $theme->slug,
        ]);

        return $deactivated;
    }

    public function delete(string $themeId, string $deletedBy, string $reason): void
    {
        $theme = $this->repository->findById($themeId);

        if ($theme === null) {
            throw CmsException::themeNotFound($themeId);
        }

        if ($theme->isActive) {
            throw CmsException::themeIsActive($themeId);
        }

        // Remove theme storage directory
        if (is_dir($theme->storagePath)) {
            $this->removeDirectory($theme->storagePath);
        }

        // Remove deployed assets
        $assetDir = rtrim($this->publicPath, '/') . '/cms-assets/' . $theme->slug;

        if (is_dir($assetDir)) {
            $this->removeDirectory($assetDir);
        }

        // Soft delete the record
        $this->repository->delete($themeId);

        $this->eventDispatcher->dispatch(new ThemeDeleted(
            themeId: $themeId,
            deletedBy: $deletedBy,
            reason: $reason,
        ));

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $deletedBy,
            'cms.theme.deleted',
            "theme:$themeId",
            ['slug' => $theme->slug, 'reason' => $reason],
        );

        $this->logger->info('Theme deleted', [
            'id' => $themeId,
            'slug' => $theme->slug,
            'reason' => $reason,
        ]);
    }

    public function preview(string $themeId, string $userId): PreviewSession
    {
        $theme = $this->repository->findById($themeId);

        if ($theme === null) {
            throw CmsException::themeNotFound($themeId);
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = new DateTimeImmutable()->modify('+30 minutes');

        $session = new PreviewSession(
            themeId: $themeId,
            token: $token,
            userId: $userId,
            expiresAt: $expiresAt,
        );

        $this->previewSessionRepository->save($session);

        return $session;
    }

    public function rollback(string $rolledBackBy, ?string $tenantId = null): InstalledTheme
    {
        // Find the most recently deactivated theme
        $all = $this->repository->findAll($tenantId);
        $candidate = null;

        foreach ($all as $theme) {
            if (!$theme->isActive && $theme->deactivatedAt !== null) {
                if ($candidate === null || $theme->deactivatedAt > $candidate->deactivatedAt) {
                    $candidate = $theme;
                }
            }
        }

        if ($candidate === null) {
            throw CmsException::noPreviousTheme();
        }

        return $this->activate($candidate->id, $rolledBackBy);
    }

    public function getActive(?string $tenantId = null): ?InstalledTheme
    {
        return $this->repository->findActive($tenantId);
    }

    public function getInstalled(?string $tenantId = null): array
    {
        return $this->repository->findAll($tenantId);
    }

    /**
     * Deploy theme assets to the public directory, filtering to safe extensions only.
     */
    private function deployAssets(InstalledTheme $theme): void
    {
        $sourceDir = $theme->storagePath . '/assets';

        if (!is_dir($sourceDir)) {
            return;
        }

        $targetDir = rtrim($this->publicPath, '/') . '/cms-assets/' . $theme->slug;

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0o755, true);
        }

        $this->copyDirectoryFiltered($sourceDir, $targetDir);
    }

    /**
     * Copy directory contents, only including files with safe extensions.
     */
    private function copyDirectoryFiltered(string $source, string $target): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $targetPath = $target . '/' . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0o755, true);
                }
            } elseif ($this->isSafeExtension($item->getExtension())) {
                $parentDir = dirname($targetPath);

                if (!is_dir($parentDir)) {
                    mkdir($parentDir, 0o755, true);
                }

                copy($item->getPathname(), $targetPath);
            }
        }
    }

    private function isSafeExtension(string $extension): bool
    {
        return in_array(strtolower($extension), self::SAFE_ASSET_EXTENSIONS, true);
    }

    private function moveDirectory(string $source, string $target): void
    {
        $parentDir = dirname($target);

        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0o755, true);
        }

        rename($source, $target);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path) || !$this->isInsideAllowedRoot($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }

    /**
     * Defence-in-depth: confirm the directory to remove resolves inside the
     * configured theme storage path, the public asset directory, or the
     * system temp dir. Guards against any future regression in slug
     * validation or DB tampering.
     */
    private function isInsideAllowedRoot(string $path): bool
    {
        $resolved = realpath($path);

        if ($resolved === false) {
            return false;
        }

        $roots = [
            $this->config->storagePath,
            rtrim($this->publicPath, '/') . '/cms-assets',
            sys_get_temp_dir(),
        ];

        foreach ($roots as $root) {
            $rootReal = realpath($root);

            if ($rootReal === false) {
                continue;
            }

            $rootWithSep = rtrim($rootReal, '/\\') . DIRECTORY_SEPARATOR;

            if (str_starts_with($resolved, $rootWithSep)) {
                return true;
            }
        }

        return false;
    }
}
