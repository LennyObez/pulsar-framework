<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use Pulsar\Api\Internal;
use Pulsar\Core\Version;
use Pulsar\Integrity\Exception\IntegrityException;

use function count;
use function filesize;
use function hash_file;
use function is_file;
use function sort;
use function str_replace;
use function time;
use function usort;

use const DIRECTORY_SEPARATOR;

/**
 * Builds an integrity manifest by scanning the filesystem.
 *
 * Discovery belongs to {@see ManifestScope}, which the manifest then carries so
 * the verifier scans exactly what was hashed.
 */
#[Internal]
final class ManifestBuilder implements ManifestBuilderInterface
{
    private const string ALGORITHM = 'sha256';

    public function __construct(
        private readonly string $basePath,
        // Defaulted rather than required: every existing caller keeps working, and
        // the verifier is handed the same contract so both sides of the control
        // can be pointed at one walk.
        private readonly ManifestScopeWalkerInterface $walker = new ManifestScopeWalker(),
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
        $scope = new ManifestScope($includePaths, $excludePaths);
        $files = $this->walker->discover($scope, $this->basePath);
        sort($files);

        $entries = [];

        foreach ($files as $relativePath) {
            $absolutePath = $this->basePath
                . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

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
            version: IntegrityManifest::VERSION,
            algorithm: self::ALGORITHM,
            generatedAt: time(),
            frameworkVersion: Version::full(),
            entryCount: count($entries),
            entries: $entries,
            scope: $scope,
        );
    }
}
