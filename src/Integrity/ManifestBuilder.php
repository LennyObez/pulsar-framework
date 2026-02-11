<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use FilesystemIterator;
use Pulsar\Api\Internal;
use Pulsar\Core\Version;
use Pulsar\Integrity\Exception\IntegrityException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_keys;
use function count;
use function explode;
use function filesize;
use function fnmatch;
use function hash_file;
use function implode;
use function is_dir;
use function is_file;
use function ltrim;
use function sort;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function time;
use function usort;

use const DIRECTORY_SEPARATOR;
use const FNM_PATHNAME;

/**
 * Builds an integrity manifest by scanning the filesystem.
 *
 * Discovers files matching include patterns, excludes files matching
 * exclude patterns, and computes SHA-256 hashes for each tracked file.
 */
#[Internal]
final class ManifestBuilder implements ManifestBuilderInterface
{
    private const string ALGORITHM = 'sha256';
    private const int MANIFEST_VERSION = 1;
    public function __construct(
        private readonly string $basePath,
    ) {}

    /**
     * Build an integrity manifest from the given include/exclude patterns.
     *
     * @param list<string> $includePaths Glob patterns for files to include
     * @param list<string> $excludePaths Glob patterns for files to exclude
     *
     * @throws IntegrityException If the build process fails
     */
    public function build(array $includePaths, array $excludePaths): IntegrityManifest
    {
        $files = $this->discoverFiles($includePaths, $excludePaths);
        sort($files);

        $entries = [];

        foreach ($files as $relativePath) {
            $absolutePath = $this->basePath . DIRECTORY_SEPARATOR . $relativePath;

            if (!is_file($absolutePath)) {
                continue;
            }

            $hash = hash_file(self::ALGORITHM, $absolutePath);

            if ($hash === false) {
                throw IntegrityException::buildFailed(
                    'failed to compute hash for "' . $relativePath . '"',
                );
            }

            $size = filesize($absolutePath);

            if ($size === false) {
                throw IntegrityException::buildFailed(
                    'failed to read file size for "' . $relativePath . '"',
                );
            }

            $entries[] = new ManifestEntry(
                path: $relativePath,
                hash: $hash,
                size: $size,
            );
        }

        usort($entries, static fn(ManifestEntry $a, ManifestEntry $b): int => $a->path <=> $b->path);

        return new IntegrityManifest(
            version: self::MANIFEST_VERSION,
            algorithm: self::ALGORITHM,
            generatedAt: time(),
            frameworkVersion: Version::full(),
            entryCount: count($entries),
            entries: $entries,
        );
    }

    /**
     * Discover files matching include patterns, filtering out excluded ones.
     *
     * @param list<string> $includePaths Glob patterns to include
     * @param list<string> $excludePaths Glob patterns to exclude
     *
     * @return list<string> Relative file paths
     */
    private function discoverFiles(array $includePaths, array $excludePaths): array
    {
        $matched = [];

        foreach ($includePaths as $pattern) {
            $baseDir = $this->extractBaseDir($pattern);
            $scanPath = $this->basePath . DIRECTORY_SEPARATOR . $baseDir;

            if (!is_dir($scanPath)) {
                continue;
            }

            $isRecursive = str_contains($pattern, '**');

            if ($isRecursive) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(
                        $scanPath,
                        FilesystemIterator::SKIP_DOTS,
                    ),
                );
            } else {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(
                        $scanPath,
                        FilesystemIterator::SKIP_DOTS,
                    ),
                    RecursiveIteratorIterator::SELF_FIRST,
                    RecursiveIteratorIterator::CATCH_GET_CHILD,
                );
            }

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $relativePath = $this->toRelativePath($file->getPathname());

                if ($this->matchesGlob($relativePath, $pattern)) {
                    $matched[$relativePath] = true;
                }
            }
        }

        // Filter out excluded files
        foreach (array_keys($matched) as $path) {
            foreach ($excludePaths as $excludePattern) {
                if ($this->matchesGlob($path, $excludePattern)) {
                    unset($matched[$path]);
                    break;
                }
            }
        }

        /** @var list<string> */
        return array_keys($matched);
    }

    /**
     * Extract the static base directory from a glob pattern.
     *
     * For example, "src/** /*.php" returns "src", "config/*.php" returns "config".
     */
    private function extractBaseDir(string $pattern): string
    {
        $normalized = str_replace('\\', '/', $pattern);
        $parts = explode('/', $normalized);
        $base = [];

        foreach ($parts as $part) {
            if (str_contains($part, '*') || str_contains($part, '?') || str_contains($part, '[')) {
                break;
            }
            $base[] = $part;
        }

        return $base !== [] ? implode(DIRECTORY_SEPARATOR, $base) : '.';
    }

    /**
     * Convert an absolute path to a path relative to the base directory.
     *
     * Always uses forward slashes for consistent cross-platform manifest entries.
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

    /**
     * Match a relative path against a glob pattern.
     *
     * Translates ** to match across directory boundaries, and uses
     * fnmatch() for single-level wildcard matching.
     */
    private function matchesGlob(string $path, string $pattern): bool
    {
        // Normalize to forward slashes for consistent matching
        $path = str_replace('\\', '/', $path);
        $pattern = str_replace('\\', '/', $pattern);

        // Handle ** (recursive) patterns
        if (str_contains($pattern, '**')) {
            // Convert ** glob to a fnmatch-compatible pattern
            // "src/**/*.php" should match "src/Foo/Bar/Baz.php"
            $fnmatchPattern = str_replace('**/', '*', $pattern);
            $fnmatchPattern = str_replace('/**', '/*', $fnmatchPattern);

            // Try direct fnmatch with FNM_PATHNAME disabled (allows / matching)
            if (fnmatch($pattern, $path)) {
                return true;
            }

            // Also try the simplified pattern without FNM_PATHNAME
            return fnmatch($fnmatchPattern, $path);
        }

        // For non-recursive patterns, use FNM_PATHNAME to prevent * from crossing /
        return fnmatch($pattern, $path, FNM_PATHNAME);
    }
}
