<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use function array_keys;
use function is_dir;
use function ksort;
use function preg_match;

use Pulsar\Api\Api;
use Pulsar\Database\Exception\DatabaseException;

use function scandir;
use function str_ends_with;
use function substr;

/**
 * Discovers migration files from disk.
 *
 * Migration filenames must follow the convention:
 * {YYYYMMDDHHMMSS}_description_snake_case.php
 */
#[Api]
final readonly class MigrationRepository
{
    public function __construct(
        private string $migrationsPath,
    ) {}

    /**
     * Discover all migration files, sorted by version (ascending).
     *
     * @return array<string, MigrationFile> Keyed by version string
     * @throws DatabaseException On duplicate versions
     */
    public function discover(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = scandir($this->migrationsPath);
        if ($files === false) {
            return [];
        }

        $migrations = [];

        foreach ($files as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }

            $version = $this->extractVersion($file);
            if ($version === null) {
                continue;
            }

            if (isset($migrations[$version])) {
                throw DatabaseException::duplicateMigrationVersion($version);
            }

            $name = $this->extractName($file);
            $path = $this->migrationsPath . DIRECTORY_SEPARATOR . $file;

            $migrations[$version] = new MigrationFile(
                version: $version,
                name: $name,
                path: $path,
            );
        }

        ksort($migrations);

        return $migrations;
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
     * Extract the version timestamp from a filename.
     *
     * @return string|null The 14-digit version, or null if not a valid migration filename.
     */
    public function extractVersion(string $filename): ?string
    {
        if (preg_match('/^(\d{14})_/', $filename, $matches) === 1) {
            return $matches[1];
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

        // Remove the version prefix (14 digits + underscore)
        if (preg_match('/^\d{14}_(.+)$/', $withoutExt, $matches) === 1) {
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
        return array_keys($this->discover());
    }
}
