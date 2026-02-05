<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use FilesystemIterator;
use Pulsar\Api\Internal;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_diff_key;
use function array_keys;
use function dirname;
use function hash_file;
use function is_dir;
use function is_file;
use function ltrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies an integrity manifest against the current filesystem state.
 *
 * Detects modified files (hash mismatch), missing files (no longer on disk),
 * and added files (on disk but not in manifest).
 */
#[Internal]
final readonly class ManifestVerifier implements ManifestVerifierInterface
{
    public function __construct(
        private string $basePath,
    ) {}

    /**
     * Verify an integrity manifest against the current filesystem.
     *
     * Checks every manifest entry for existence and hash correctness,
     * and scans for files present on disk but absent from the manifest.
     */
    public function verify(IntegrityManifest $manifest): VerificationResult
    {
        $files = [];
        $verified = 0;
        $modified = 0;
        $missing = 0;
        $added = 0;

        // Build a lookup of manifest entries by path
        $manifestPaths = [];
        foreach ($manifest->entries as $entry) {
            $manifestPaths[$entry->path] = $entry;
        }

        // Check each manifest entry against the filesystem
        foreach ($manifest->entries as $entry) {
            $absolutePath = $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry->path);

            if (!is_file($absolutePath)) {
                $files[] = new FileVerificationResult(
                    path: $entry->path,
                    status: FileVerificationStatus::Missing,
                    expectedHash: $entry->hash,
                    actualHash: null,
                );
                $missing++;
                continue;
            }

            $actualHash = hash_file($manifest->algorithm, $absolutePath);

            if ($actualHash === false) {
                // Treat unreadable files as modified
                $files[] = new FileVerificationResult(
                    path: $entry->path,
                    status: FileVerificationStatus::Modified,
                    expectedHash: $entry->hash,
                    actualHash: null,
                );
                $modified++;
                continue;
            }

            if ($actualHash !== $entry->hash) {
                $files[] = new FileVerificationResult(
                    path: $entry->path,
                    status: FileVerificationStatus::Modified,
                    expectedHash: $entry->hash,
                    actualHash: $actualHash,
                );
                $modified++;
                continue;
            }

            $files[] = new FileVerificationResult(
                path: $entry->path,
                status: FileVerificationStatus::Verified,
                expectedHash: $entry->hash,
                actualHash: $actualHash,
            );
            $verified++;
        }

        // Scan for added files (present on disk but not in manifest)
        $currentFiles = $this->scanCurrentFiles($manifest);
        $addedPaths = array_diff_key($currentFiles, $manifestPaths);

        foreach (array_keys($addedPaths) as $addedPath) {
            $absolutePath = $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $addedPath);
            $actualHash = hash_file($manifest->algorithm, $absolutePath);

            $files[] = new FileVerificationResult(
                path: $addedPath,
                status: FileVerificationStatus::Added,
                expectedHash: null,
                actualHash: $actualHash !== false ? $actualHash : null,
            );
            $added++;
        }

        $passed = $modified === 0 && $missing === 0;

        return new VerificationResult(
            passed: $passed,
            verified: $verified,
            modified: $modified,
            missing: $missing,
            added: $added,
            files: $files,
        );
    }

    /**
     * Scan the filesystem for files in the same directories as manifest entries.
     *
     * This discovers files that exist on disk to detect "added" files not
     * present in the manifest.
     *
     * @return array<string, true> Map of relative paths to true
     */
    private function scanCurrentFiles(IntegrityManifest $manifest): array
    {
        // Collect unique base directories from manifest entries
        $directories = [];
        foreach ($manifest->entries as $entry) {
            $dir = dirname($entry->path);
            if ($dir === '.') {
                $dir = '';
            }
            $directories[$dir] = true;
        }

        $currentFiles = [];

        foreach (array_keys($directories) as $dir) {
            $scanPath = $dir !== ''
                ? $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir)
                : $this->basePath;

            if (!is_dir($scanPath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $scanPath,
                    FilesystemIterator::SKIP_DOTS,
                ),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $relativePath = $this->toRelativePath($file->getPathname());
                $currentFiles[$relativePath] = true;
            }
        }

        return $currentFiles;
    }

    /**
     * Convert an absolute path to a path relative to the base directory.
     *
     * Uses forward slashes for consistent cross-platform paths.
     */
    private function toRelativePath(string $absolutePath): string
    {
        $normalized = str_replace('\\', '/', $absolutePath);
        $normalizedBase = str_replace('\\', '/', $this->basePath);

        if (str_starts_with($normalized, $normalizedBase . '/')) {
            return ltrim(substr($normalized, strlen($normalizedBase)), '/');
        }

        return $normalized;
    }
}
