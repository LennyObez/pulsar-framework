<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use Pulsar\Api\Api;
use Pulsar\Database\Exception\DatabaseException;

use function array_keys;
use function array_values;
use function is_dir;
use function is_string;
use function ksort;
use function preg_match;
use function scandir;
use function str_ends_with;
use function str_pad;
use function substr;

/**
 * Discovers migration files from disk.
 *
 * Supports multiple migration directories (project + extensions).
 * Migration filenames must follow the convention:
 * {YYYYMMDDHHMMSS}_description_snake_case.php
 * @api
 */
#[Api(since: '1.0.0')]
final class MigrationRepository
{
    /** @var list<string> */
    private readonly array $migrationsPaths;

    /** @var array<string, MigrationFile>|null */
    private ?array $discoveryCache = null;

    /**
     * @param list<string>|string $migrationsPaths One or more directories to scan
     */
    public function __construct(array|string $migrationsPaths)
    {
        $this->migrationsPaths = is_string($migrationsPaths) ? [$migrationsPaths] : array_values($migrationsPaths);
    }

    /**
     * Discover all migration files across all paths, sorted by version (ascending).
     *
     * Results are cached in memory so repeated calls within the same process
     * return instantly without re-scanning the filesystem. Call
     * {@see clearCache()} to force a fresh scan.
     *
     * @return array<string, MigrationFile> Keyed by version string
     * @throws DatabaseException On duplicate versions
     */
    public function discover(): array
    {
        if ($this->discoveryCache !== null) {
            return $this->discoveryCache;
        }

        $migrations = [];

        foreach ($this->migrationsPaths as $migrationsPath) {
            if (!is_dir($migrationsPath)) {
                continue;
            }

            $files = scandir($migrationsPath);
            if ($files === false) {
                continue;
            }

            // Compute a path prefix for sequential versions to prevent
            // collisions across extensions (e.g., CMS 001_ vs Forum 001_).
            // Timestamp versions are globally unique and need no prefix.
            $pathPrefix = $this->computePathPrefix($migrationsPath);

            foreach ($files as $file) {
                if (!str_ends_with($file, '.php')) {
                    continue;
                }

                // F11.17: parse name + version + sequentiality in
                // a single pass instead of three separate regex
                // sweeps. Each filename hits at most one match
                // attempt per format on the way in, vs the
                // previous up-to-7 sweeps via extractVersion +
                // extractName + isSequentialVersion.
                $parsed = $this->parseFilename($file);
                if ($parsed === null) {
                    continue;
                }

                [$rawVersion, $name, $isSequential] = $parsed;

                // Sequential versions get a path-scoped prefix to avoid
                // collisions: "a3f2_00000000000001" vs "7b1c_00000000000001"
                $version = $isSequential
                    ? $pathPrefix . '_' . $rawVersion
                    : $rawVersion;

                if (isset($migrations[$version])) {
                    throw DatabaseException::duplicateMigrationVersion($version);
                }

                $path = $migrationsPath . DIRECTORY_SEPARATOR . $file;

                $migrations[$version] = new MigrationFile(
                    version: $version,
                    name: $name,
                    path: $path,
                );
            }
        }

        ksort($migrations);

        $this->discoveryCache = $migrations;

        return $migrations;
    }

    /**
     * Clear the in-memory discovery cache.
     *
     * Subsequent calls to {@see discover()} will re-scan the filesystem.
     */
    public function clearCache(): void
    {
        $this->discoveryCache = null;
    }

    /**
     * F11.17: parse a filename into (version, name, isSequential)
     * in a single regex pass per format. Replaces the
     * `extractVersion` + `extractName` + `isSequentialVersion`
     * triple-sweep that was up to 7 regex calls per file during
     * `discover()`.
     *
     * @return array{0: string, 1: string, 2: bool}|null
     *          [version, name, isSequential] or null when the
     *          filename does not match any known format.
     */
    private function parseFilename(string $filename): ?array
    {
        // Strip the .php suffix once for both name extraction and
        // the trailing-pattern matches below.
        $withoutExt = str_ends_with($filename, '.php')
            ? substr($filename, 0, -4)
            : $filename;

        // Compact: YYYYMMDDHHMMSS_description
        if (preg_match('/^(\d{14})_(.+)$/', $withoutExt, $matches) === 1) {
            return [$matches[1], $matches[2], false];
        }

        // Separated: YYYY_MM_DD_HHMMSS_description
        if (preg_match('/^(\d{4})_(\d{2})_(\d{2})_(\d{6})_(.+)$/', $withoutExt, $matches) === 1) {
            return [$matches[1] . $matches[2] . $matches[3] . $matches[4], $matches[5], false];
        }

        // Sequential: 1-13 digits + description
        if (preg_match('/^(\d{1,13})_(.+)$/', $withoutExt, $matches) === 1) {
            return [str_pad($matches[1], 14, '0', STR_PAD_LEFT), $matches[2], true];
        }

        return null;
    }

    /**
     * Compute a short deterministic prefix from a directory path.
     *
     * Uses the first 4 hex chars of a CRC32 hash, giving 65,536 buckets.
     * Collisions are astronomically unlikely for < 100 extensions.
     */
    private function computePathPrefix(string $path): string
    {
        return substr(hash('crc32b', $path), 0, 4);
    }

    /**
     * Load a migration instance from a file path.
     *
     * @throws DatabaseException If the file does not return a MigrationInterface.
     */
    public function load(string $filePath): MigrationInterface
    {
        /**
         * @psalm-suppress UnresolvableInclude Migration file paths are validated at runtime via discover()
         */
        $migration = require $filePath;

        if (!$migration instanceof MigrationInterface) {
            throw DatabaseException::migrationFileInvalid($filePath);
        }

        return $migration;
    }

    /**
     * Extract the version from a filename.
     *
     * Accepts three formats:
     *   - Compact:     YYYYMMDDHHMMSS_description.php    (e.g. 20260203153000_create_users.php)
     *   - Separated:   YYYY_MM_DD_HHMMSS_description.php (e.g. 2026_02_03_153000_create_users.php)
     *   - Sequential:  NNN_description.php                (e.g. 001_create_table.php)
     *
     * Compact and separated formats normalize to a 14-digit version string.
     * Sequential format zero-pads to 14 digits for consistent ordering.
     *
     * @return string|null The 14-digit version, or null if not a valid migration filename.
     */
    public function extractVersion(string $filename): ?string
    {
        // Compact format: 14 consecutive digits followed by underscore
        if (preg_match('/^(\d{14})_/', $filename, $matches) === 1) {
            return $matches[1];
        }

        // Separated format: YYYY_MM_DD_HHMMSS followed by underscore
        if (preg_match('/^(\d{4})_(\d{2})_(\d{2})_(\d{6})_/', $filename, $matches) === 1) {
            return $matches[1] . $matches[2] . $matches[3] . $matches[4];
        }

        // Sequential format: 1-13 digits followed by underscore (e.g. 001_create_table.php)
        if (preg_match('/^(\d{1,13})_/', $filename, $matches) === 1) {
            return str_pad($matches[1], 14, '0', STR_PAD_LEFT);
        }

        return null;
    }

    /**
     * Extract the human-readable name from a filename.
     */
    public function extractName(string $filename): string
    {
        // Remove .php extension
        $withoutExt = substr($filename, 0, -4);

        // Compact format: 14 digits + underscore
        if (preg_match('/^\d{14}_(.+)$/', $withoutExt, $matches) === 1) {
            return $matches[1];
        }

        // Separated format: YYYY_MM_DD_HHMMSS + underscore
        if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_(.+)$/', $withoutExt, $matches) === 1) {
            return $matches[1];
        }

        // Sequential format: 1-13 digits + underscore
        if (preg_match('/^\d{1,13}_(.+)$/', $withoutExt, $matches) === 1) {
            return $matches[1];
        }

        return $withoutExt;
    }

    /**
     * Get all discovered version strings.
     *
     * @return list<string>
     */
    public function versions(): array
    {
        return array_map(strval(...), array_keys($this->discover()));
    }
}
