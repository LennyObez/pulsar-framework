<?php

declare(strict_types=1);

namespace Pulsar\Support;

use JsonException;
use NoDiscard;
use Pulsar\Api\Internal;

use function file_get_contents;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function rtrim;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

/**
 * Resolves a project's source directories from its composer.json PSR-4 autoload
 * map — the authoritative source of truth — instead of assuming the scaffolder's
 * literal `src/` layout.
 *
 * A project mapping `"App\\": "app/"` has no `src/` directory, so any tool that
 * hardcoded `basePath . '/src'` either scanned nothing or threw
 * (RecursiveDirectoryIterator "Failed to open directory"). Every mapped root is
 * unioned, resolved against the project root, and filtered to those that exist —
 * a declared-but-absent root is skipped, never fatal.
 */
#[Internal(reason: 'Project layout resolution for cache warm, preload and extraction')]
final class ProjectSourceRoots
{
    /**
     * The project's existing source roots, derived from composer.json
     * `autoload.psr-4` (production autoload only — dev-only roots are not part
     * of a warmed/preloaded application).
     *
     * Falls back to `<basePath>/src` when composer.json is missing, unreadable,
     * malformed, or declares no usable PSR-4 root — and returns an empty list
     * when even that does not exist, so callers can skip rather than fail.
     *
     * @return list<string> Absolute directory paths, without a trailing separator.
     */
    #[NoDiscard]
    public static function discover(string $basePath): array
    {
        $basePath = rtrim($basePath, '/\\');
        $roots = [];

        foreach (self::psr4Prefixes($basePath) as $relative) {
            $path = self::resolve($basePath, $relative);

            if ($path !== null && !in_array($path, $roots, true)) {
                $roots[] = $path;
            }
        }

        if ($roots !== []) {
            return $roots;
        }

        $fallback = $basePath . DIRECTORY_SEPARATOR . 'src';

        return is_dir($fallback) ? [$fallback] : [];
    }

    /**
     * Every path declared in composer.json's `autoload.psr-4` map. A PSR-4 prefix
     * may map to a single path or a list of paths; both shapes are flattened.
     *
     * @return list<string>
     */
    private static function psr4Prefixes(string $basePath): array
    {
        $manifest = $basePath . DIRECTORY_SEPARATOR . 'composer.json';

        if (!is_file($manifest)) {
            return [];
        }

        $raw = file_get_contents($manifest);

        if ($raw === false) {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded) || !is_array($decoded['autoload'] ?? null)) {
            return [];
        }

        /** @var mixed $psr4 */
        $psr4 = $decoded['autoload']['psr-4'] ?? null;

        if (!is_array($psr4)) {
            return [];
        }

        $paths = [];

        /** @var mixed $target */
        foreach ($psr4 as $target) {
            if (is_string($target)) {
                $paths[] = $target;

                continue;
            }

            if (is_array($target)) {
                /** @var mixed $entry */
                foreach ($target as $entry) {
                    if (is_string($entry)) {
                        $paths[] = $entry;
                    }
                }
            }
        }

        return $paths;
    }

    /**
     * Resolve a declared relative root against the project root, returning null
     * when it is empty, escapes the project, or does not exist on disk.
     */
    private static function resolve(string $basePath, string $relative): ?string
    {
        $relative = rtrim($relative, '/\\');

        if ($relative === '' || $relative === '.') {
            return null;
        }

        // Defence in depth: a manifest must not point the scanner outside the
        // project (e.g. "../../etc"); such a root is ignored rather than walked.
        if (str_starts_with($relative, '..')) {
            return null;
        }

        $path = $basePath . DIRECTORY_SEPARATOR . $relative;

        return is_dir($path) ? $path : null;
    }
}
