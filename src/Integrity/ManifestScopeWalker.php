<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use FilesystemIterator;
use Pulsar\Api\Internal;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_keys;
use function is_dir;
use function ltrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * Walks the disk for {@see ManifestScopeWalkerInterface}.
 *
 * Scanning starts from the static prefix of each include pattern rather than
 * from the base path, so a scope covering `src/**\/*.php` never descends into
 * vendor or node_modules to throw the results away afterwards.
 */
#[Internal]
final readonly class ManifestScopeWalker implements ManifestScopeWalkerInterface
{
    public function discover(ManifestScope $scope, string $basePath): array
    {
        $matched = [];

        foreach ($scope->scanRoots() as $root) {
            $scanPath = $basePath . DIRECTORY_SEPARATOR . $root;

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

                $relativePath = $this->toRelativePath($basePath, $file->getPathname());

                if ($scope->covers($relativePath)) {
                    $matched[$relativePath] = true;
                }
            }
        }

        /** @var list<string> */
        return array_keys($matched);
    }

    /**
     * Convert an absolute path to a forward-slash path relative to the base.
     */
    private function toRelativePath(string $basePath, string $absolutePath): string
    {
        $normalized = str_replace('\\', '/', $absolutePath);
        $normalizedBase = str_replace('\\', '/', $basePath);

        if (str_starts_with($normalized, $normalizedBase . '/')) {
            return ltrim(substr($normalized, strlen($normalizedBase)), '/');
        }

        return $normalized;
    }
}
