<?php

declare(strict_types=1);

namespace Pulsar\Codegen;

use InvalidArgumentException;
use Pulsar\Api\Api;

use function array_map;
use function implode;
use function rtrim;
use function str_contains;
use function str_starts_with;

/**
 * Validates generated file paths against a configurable directory allowlist.
 *
 * Prevents directory traversal attacks and ensures all output stays within
 * permitted directories.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PathValidator
{
    /** @var list<string> Allowed directory prefixes (absolute paths) */
    private array $allowedPrefixes;

    /**
     * @param string $projectRoot The project root directory (absolute path)
     * @param list<string> $allowedDirectories Relative directory allowlist (e.g., ['src/', 'tests/'])
     */
    public function __construct(
        private string $projectRoot,
        array $allowedDirectories = ['src/', 'tests/', 'config/', 'database/migrations/'],
    ) {
        $normalizedRoot = rtrim($this->normalizePath($projectRoot), '/') . '/';

        $this->allowedPrefixes = array_map(
            static fn(string $dir): string => $normalizedRoot . rtrim($dir, '/') . '/',
            $allowedDirectories,
        );
    }

    /**
     * Validate that a target path is within the allowed directories.
     *
     * @param string $targetPath The path to validate (absolute or relative to project root)
     *
     * @return string The validated absolute path (normalized)
     *
     * @throws InvalidArgumentException If the path is outside the allowlist or uses traversal
     */
    public function validate(string $targetPath): string
    {
        $normalized = $this->normalizePath($targetPath);

        // Reject directory traversal attempts
        if (str_contains($normalized, '..')) {
            throw new InvalidArgumentException(
                "Path contains directory traversal: $targetPath",
            );
        }

        // If relative, resolve against project root
        if (!$this->isAbsolute($normalized)) {
            $root = rtrim($this->normalizePath($this->projectRoot), '/');
            $normalized = $root . '/' . $normalized;
        }

        // Check against allowlist
        if (array_any($this->allowedPrefixes, static fn(string $prefix): bool => str_starts_with($normalized, $prefix))) {
            return $normalized;
        }

        throw new InvalidArgumentException(
            "Path is outside allowed directories: $targetPath. "
            . 'Allowed: ' . implode(', ', $this->allowedPrefixes),
        );
    }

    /**
     * Check whether a path would pass validation without throwing.
     */
    public function isAllowed(string $targetPath): bool
    {
        $normalized = $this->normalizePath($targetPath);

        if (str_contains($normalized, '..')) {
            return false;
        }

        if (!$this->isAbsolute($normalized)) {
            $root = rtrim($this->normalizePath($this->projectRoot), '/');
            $normalized = $root . '/' . $normalized;
        }

        return array_any($this->allowedPrefixes, static fn(string $prefix): bool => str_starts_with($normalized, $prefix));
    }

    /**
     * Normalize a path to use forward slashes consistently.
     */
    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * Check whether a path is absolute.
     */
    private function isAbsolute(string $path): bool
    {
        // Unix absolute path
        if (str_starts_with($path, '/')) {
            return true;
        }

        // Windows absolute path (e.g., C:/)
        if (isset($path[2]) && $path[1] === ':' && $path[2] === '/') {
            return true;
        }

        return false;
    }
}
