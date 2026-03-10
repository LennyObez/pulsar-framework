<?php

declare(strict_types=1);

namespace Pulsar\Filesystem;

use Pulsar\Api\Api;

use function dirname;
use function getcwd;
use function is_dir;
use function preg_match;
use function realpath;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;

/**
 * Path-traversal-safe filesystem path.
 *
 * F3.3: scaffold and removal commands accept user-supplied path
 * fragments (e.g. `--path` option). Concatenating these fragments
 * with `getcwd()` gives a path that may escape the project root via
 * `..`, an absolute prefix (`/etc/passwd`), a NUL truncation, or a
 * symlink mid-tree. This value object is the single chokepoint
 * through which every scaffold-touching path must pass: the only
 * way to obtain a `SafePath` instance is to call a factory that
 * runs all four validation passes; once held, the absolute string
 * form is guaranteed to live under the boundary supplied at
 * construction.
 *
 * The factory returns `null` on a rejected path so callers can
 * distinguish "untrusted input was malicious" from "path was
 * legitimately empty"; rejecting via a thrown exception would force
 * try/catch on every option-parse path.
 */
#[Api(since: '1.0.0')]
final readonly class SafePath
{
    /**
     * @param non-empty-string $absolute Absolute path validated to live under boundary
     * @param non-empty-string $boundary Realpath of the trust boundary the value object guarantees
     */
    private function __construct(
        public string $absolute,
        public string $boundary,
    ) {}

    /**
     * Resolve a user-supplied relative path under the current working
     * directory. Returns null on:
     *   - NUL byte (silent C-string truncation),
     *   - absolute path attempt (Unix `/...`, Windows `C:\...`),
     *   - any explicit `..` segment,
     *   - cwd unavailable,
     *   - resolution outside cwd realpath.
     */
    public static function resolveUnderCwd(string $relativePath): ?self
    {
        $cwd = getcwd();
        if ($cwd === false) {
            return null;
        }

        return self::resolveUnder($relativePath, $cwd);
    }

    /**
     * Same contract as {@see resolveUnderCwd()} but with an explicit
     * boundary directory. Useful for tests and for callers that
     * scope under e.g. `tests/` or `extensions/`.
     */
    public static function resolveUnder(string $relativePath, string $boundaryDir): ?self
    {
        if ($relativePath === '') {
            return null;
        }

        if (
            str_contains($relativePath, "\0")
            || preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $relativePath) === 1
            || self::isAbsolutePath($relativePath)
        ) {
            return null;
        }

        $boundaryReal = realpath($boundaryDir);
        if ($boundaryReal === false) {
            return null;
        }

        $candidate = $boundaryReal . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        $verified = self::verifyUnderBoundary($candidate, $boundaryReal);
        if ($verified === null) {
            return null;
        }

        return new self($verified, $boundaryReal);
    }

    /**
     * Adopt an already-resolved absolute path. The path must already
     * exist (otherwise a malicious caller could fabricate any string
     * and skip the realpath check). Used for paths derived from
     * `scandir()` of an already-trusted SafePath directory.
     */
    public static function adoptExisting(string $absolutePath, self $boundary): ?self
    {
        $verified = self::verifyUnderBoundary($absolutePath, $boundary->boundary);
        if ($verified === null) {
            return null;
        }

        return new self($verified, $boundary->boundary);
    }

    /**
     * Build a SafePath for a child entry of an existing SafePath
     * directory. The `$name` segment must be a single path
     * component without any traversal characters. This is the
     * canonical way to walk a tree obtained via scandir().
     */
    public function child(string $name): ?self
    {
        if (
            $name === ''
            || $name === '.'
            || $name === '..'
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, "\0")
        ) {
            return null;
        }

        $candidate = $this->absolute . DIRECTORY_SEPARATOR . $name;
        $verified = self::verifyUnderBoundary($candidate, $this->boundary);
        if ($verified === null) {
            return null;
        }

        return new self($verified, $this->boundary);
    }

    public function __toString(): string
    {
        return $this->absolute;
    }

    /**
     * Verify $candidate resolves under $boundaryReal. Walks up to the
     * nearest existing ancestor (so paths-to-create can still be
     * validated) and uses realpath to defeat symlink escapes.
     *
     * @return non-empty-string|null
     */
    private static function verifyUnderBoundary(string $candidate, string $boundaryReal): ?string
    {
        $real = realpath($candidate);

        if ($real === false) {
            // Path doesn't exist yet — verify the nearest existing
            // ancestor still lives under boundaryReal.
            $ancestorReal = self::nearestExistingAncestor($candidate);
            if ($ancestorReal === false) {
                return null;
            }
            if (!str_starts_with($ancestorReal, $boundaryReal)) {
                return null;
            }
            return $candidate;
        }

        if (!str_starts_with($real, $boundaryReal)) {
            return null;
        }

        return $real;
    }

    private static function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;
    }

    private static function nearestExistingAncestor(string $path): string|false
    {
        $current = rtrim($path, '/\\');

        while ($current !== '' && !is_dir($current)) {
            $parent = dirname($current);
            if ($parent === $current) {
                return false;
            }
            $current = $parent;
        }

        return $current === '' ? false : realpath($current);
    }
}
