<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Themes;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\ThemesConfig;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Themes\ExtractResult;
use Pulsar\Extension\Cms\Themes\ThemeArchiveExtractorInterface;
use ZipArchive;

use function dirname;
use function file_exists;
use function file_put_contents;
use function filesize;
use function is_dir;
use function mkdir;
use function realpath;
use function str_contains;
use function str_starts_with;

/**
 * Extracts ZIP archives with comprehensive Zip Slip protection.
 *
 * Security checks on every entry:
 * - Path traversal via '..' components
 * - Leading '/' (absolute paths)
 * - Null bytes in filenames
 * - Symlinks
 * - realpath() resolution to ensure the extracted path stays within the target directory
 * - Maximum archive size and file count enforcement from ThemesConfig
 */
#[Internal(reason: 'Use ThemeArchiveExtractorInterface for public API')]
final readonly class SafeArchiveExtractor implements ThemeArchiveExtractorInterface
{
    public function __construct(
        private ThemesConfig $config,
        private LoggerInterface $logger,
    ) {}

    public function extract(string $archivePath, string $targetDirectory): ExtractResult
    {
        // Validate archive exists and size
        if (!file_exists($archivePath)) {
            throw CmsException::themeExtractionFailed('Archive file does not exist');
        }

        $archiveSize = filesize($archivePath);

        if ($archiveSize === false) {
            throw CmsException::themeExtractionFailed('Cannot determine archive size');
        }

        if ($archiveSize > $this->config->maxArchiveSize) {
            throw CmsException::themeArchiveTooLarge($archiveSize, $this->config->maxArchiveSize);
        }

        // Ensure target directory exists
        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0o755, true);
        }

        $canonicalTarget = realpath($targetDirectory);

        if ($canonicalTarget === false) {
            throw CmsException::themeExtractionFailed('Cannot resolve target directory');
        }

        $canonicalTarget .= DIRECTORY_SEPARATOR;

        // Open the ZIP archive
        $zip = new ZipArchive();
        $result = $zip->open($archivePath);

        if ($result !== true) {
            throw CmsException::themeExtractionFailed('Failed to open archive (error code: ' . (string) $result . ')');
        }

        try {
            // Check file count
            if ($zip->numFiles > $this->config->maxFileCount) {
                throw CmsException::themeFileCountExceeded($zip->numFiles, $this->config->maxFileCount);
            }

            $fileCount = 0;
            $warnings = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if ($stat === false) {
                    $warnings[] = "Cannot stat entry at index $i";

                    continue;
                }

                $entryName = $stat['name'];

                // Security check: null bytes in filename
                if (str_contains($entryName, "\0")) {
                    throw CmsException::themeZipSlipDetected($entryName);
                }

                // Security check: absolute paths
                if (str_starts_with($entryName, '/') || str_starts_with($entryName, '\\')) {
                    throw CmsException::themeZipSlipDetected($entryName);
                }

                // Security check: path traversal via '..'
                if (str_contains($entryName, '..')) {
                    throw CmsException::themeZipSlipDetected($entryName);
                }

                // Security check: backslash path separators (Windows path traversal)
                if (str_contains($entryName, '\\')) {
                    throw CmsException::themeZipSlipDetected($entryName);
                }

                // Skip directories (they end with '/')
                if (str_ends_with($entryName, '/')) {
                    $dirPath = $targetDirectory . DIRECTORY_SEPARATOR . $entryName;

                    if (!is_dir($dirPath)) {
                        mkdir($dirPath, 0o755, true);
                    }

                    continue;
                }

                // Build the target file path
                $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $entryName;

                // Ensure parent directory exists
                $parentDir = dirname($targetPath);

                if (!is_dir($parentDir)) {
                    mkdir($parentDir, 0o755, true);
                }

                // Extract the file content
                $contents = $zip->getFromIndex($i);

                if ($contents === false) {
                    $warnings[] = "Failed to read entry: $entryName";

                    continue;
                }

                // Write to disk
                file_put_contents($targetPath, $contents);

                // Security check: realpath must resolve within the target directory
                $resolvedPath = realpath($targetPath);

                if ($resolvedPath === false || !str_starts_with($resolvedPath, $canonicalTarget)) {
                    // Remove the offending file immediately
                    @unlink($targetPath);

                    throw CmsException::themeZipSlipDetected($entryName);
                }

                // Security check: symlinks (check after extraction)
                if (is_link($targetPath)) {
                    @unlink($targetPath);

                    throw CmsException::themeZipSlipDetected($entryName . ' (symlink)');
                }

                $fileCount++;
            }

            $this->logger->info('Theme archive extracted safely', [
                'archive' => $archivePath,
                'target' => $targetDirectory,
                'file_count' => $fileCount,
            ]);

            return new ExtractResult(
                success: true,
                fileCount: $fileCount,
                warnings: $warnings,
            );
        } finally {
            $zip->close();
        }
    }
}
