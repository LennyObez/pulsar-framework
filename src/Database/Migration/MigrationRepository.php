<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use Pulsar\Api\Api;
use Pulsar\Database\Exception\DatabaseException;

use function array_keys;
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
 *
 * ## Where a version comes from
 *
 * A version identifies a migration in the tracking table for the life of the
 * database, so it must depend on the migration and on nothing else. Timestamp
 * filenames are globally unique and are used verbatim. Sequential filenames
 * (`001_create_pages.php`) are unique only within the directory that ships
 * them, so they are qualified by the **source** that ships them — the label the
 * caller passes alongside the directory.
 *
 * Until 1.0.0-rc.12 that qualifier was a CRC32 of the directory's ABSOLUTE
 * path. The same migration therefore had one version on a developer machine and
 * another on a deploy host, and a deploy into a different filesystem path made
 * every already-applied sequential migration look pending — re-running it
 * against a live database. Nothing about a migration changes when a checkout
 * moves, so nothing about where the checkout sits may reach the version.
 *
 * {@see MigrationRunner::getPending()} refuses to run when the tracking table
 * still holds versions of the old shape; see {@see MigrationVersionScheme} for
 * how the two are reconciled.
 * @api
 */
#[Api(since: '1.0.0')]
final class MigrationRepository
{
    /** @var list<array{label: string, path: string}> */
    private readonly array $sources;

    /** @var array<string, MigrationFile>|null */
    private ?array $discoveryCache = null;

    /**
     * @param list<string>|array<string, string>|string $migrationsPaths
     *     One directory, a list of directories, or a map of source label => directory.
     *
     *     A **labelled** entry qualifies the sequential versions found in that
     *     directory, so two sources may each ship `001_`. The label must name the
     *     source — the extension, the module, the project — and must be identical on
     *     every host, because it becomes part of the version recorded in the database.
     *     {@see MigrationPathResolver} supplies `project`, `core:<Module>` and
     *     `ext:<name>`.
     *
     *     An **unlabelled** entry (a bare string, or a list) qualifies nothing:
     *     sequential versions keep their bare zero-padded number, and two unlabelled
     *     directories shipping the same number collide loudly rather than being
     *     separated by a qualifier nobody can reproduce.
     */
    public function __construct(array|string $migrationsPaths)
    {
        if (is_string($migrationsPaths)) {
            $this->sources = [['label' => '', 'path' => $migrationsPaths]];

            return;
        }

        $sources = [];

        foreach ($migrationsPaths as $label => $path) {
            $sources[] = [
                'label' => is_string($label) ? $label : '',
                'path' => $path,
            ];
        }

        $this->sources = $sources;
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

        foreach ($this->sources as $source) {
            $migrationsPath = $source['path'];

            if (!is_dir($migrationsPath)) {
                continue;
            }

            $files = scandir($migrationsPath);
            if ($files === false) {
                continue;
            }

            // Sequential versions are qualified by the source that ships them so
            // that CMS 001_ and Forum 001_ stay distinct. Timestamp versions are
            // globally unique and take no qualifier.
            $prefix = MigrationVersionScheme::prefixForSource($source['label']);

            foreach ($files as $file) {
                if (!str_ends_with($file, '.php')) {
                    continue;
                }

                $parsed = $this->parseFilename($file);
                if ($parsed === null) {
                    continue;
                }

                [$rawVersion, $name, $isSequential] = $parsed;

                $version = $isSequential && $prefix !== ''
                    ? $prefix . '_' . $rawVersion
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
     * Parse a filename into (version, name, isSequential), extracting
     * all three in a single regex pass per supported format so
     * `discover()` costs at most one match attempt per format per file.
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
     * Load a migration instance from a file path.
     *
     * @throws DatabaseException If the file does not return a MigrationInterface.
     */
    public function load(string $filePath): MigrationInterface
    {
        /**
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
     * The returned value carries no source qualifier: it is the version as the
     * FILENAME states it, which is what a caller inspecting a filename asked for.
     * {@see discover()} is what qualifies a sequential version with its source.
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
