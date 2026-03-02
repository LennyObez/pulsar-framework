<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Themes;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Themes\InstalledTheme;
use Pulsar\Extension\Cms\Themes\ProvenanceResult;
use Pulsar\Extension\Cms\Themes\ThemeAssetResolverInterface;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;

use function file_exists;
use function hash_equals;
use function hash_file;
use function realpath;
use function rtrim;
use function str_contains;
use function str_starts_with;

/**
 * Resolves theme assets and templates to filesystem paths with path traversal protection.
 */
#[Internal(reason: 'Use ThemeAssetResolverInterface for public API')]
final readonly class ThemeAssetResolver implements ThemeAssetResolverInterface
{
    public function __construct(
        private ThemeRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {}

    public function resolve(string $assetName, ?string $themeId = null): string
    {
        $theme = $this->resolveTheme($themeId);
        $assetDir = 'public/cms-assets/' . $theme->slug;
        $assetPath = $assetDir . '/' . $assetName;

        $this->validatePathTraversal($assetPath, $assetDir, $assetName);

        if (!file_exists($assetPath)) {
            throw CmsException::themeNotFound("Asset not found: $assetName");
        }

        return '/cms-assets/' . $theme->slug . '/' . $assetName;
    }

    public function resolveTemplate(string $templateName, ?string $themeId = null): string
    {
        $theme = $this->resolveTheme($themeId);
        $templatesDir = rtrim($theme->storagePath, '/') . '/templates';
        $templatePath = $templatesDir . '/' . $templateName;

        // Append default extension if not present
        if (!str_contains($templateName, '.')) {
            $templatePath .= '.pulse.php';
        }

        $this->validatePathTraversal($templatePath, $templatesDir, $templateName);

        if (!file_exists($templatePath)) {
            throw CmsException::themeNotFound("Template not found: $templateName");
        }

        return $templatePath;
    }

    public function verifyIntegrity(string $themeId): ProvenanceResult
    {
        $theme = $this->repository->findById($themeId);

        if ($theme === null) {
            return ProvenanceResult::failed('Theme not found');
        }

        // Recompute manifest hash from the on-disk theme.json
        $manifestPath = rtrim($theme->storagePath, '/') . '/theme.json';

        if (!file_exists($manifestPath)) {
            return ProvenanceResult::failed('theme.json missing from storage');
        }

        $currentHash = hash_file('sha256', $manifestPath);

        if ($currentHash === false) {
            return ProvenanceResult::failed('Failed to compute manifest hash');
        }

        if (!hash_equals($theme->manifestHash, $currentHash)) {
            $this->logger->warning('Theme integrity check failed: manifest hash mismatch', [
                'theme_id' => $themeId,
                'slug' => $theme->slug,
                'stored_hash' => $theme->manifestHash,
                'current_hash' => $currentHash,
            ]);

            return ProvenanceResult::failed('Manifest hash mismatch: theme files may have been tampered with');
        }

        return ProvenanceResult::verified();
    }

    /**
     * Resolve a theme by ID or fall back to the active theme.
     */
    private function resolveTheme(?string $themeId): InstalledTheme
    {
        if ($themeId !== null) {
            $theme = $this->repository->findById($themeId);

            if ($theme === null) {
                throw CmsException::themeNotFound($themeId);
            }

            return $theme;
        }

        $active = $this->repository->findActive();

        if ($active === null) {
            throw CmsException::themeNotFound('No active theme');
        }

        return $active;
    }

    /**
     * Prevent path traversal by ensuring the resolved path stays within the base directory.
     *
     * @throws CmsException If path traversal is detected
     */
    private function validatePathTraversal(string $targetPath, string $baseDir, string $requestedName): void
    {
        // Quick check for obvious traversal patterns
        if (str_contains($requestedName, '..') || str_starts_with($requestedName, '/')) {
            throw CmsException::themeZipSlipDetected($requestedName);
        }

        // Null byte check
        if (str_contains($requestedName, "\0")) {
            throw CmsException::themeZipSlipDetected($requestedName);
        }

        $canonicalBase = realpath($baseDir);

        if ($canonicalBase === false) {
            return;
        }

        $canonicalTarget = realpath($targetPath);

        if ($canonicalTarget !== false && !str_starts_with($canonicalTarget, $canonicalBase)) {
            throw CmsException::themeZipSlipDetected($requestedName);
        }
    }
}
