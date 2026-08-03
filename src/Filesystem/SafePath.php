<?php

declare(strict_types=1);

namespace Pulsar\Filesystem;

use Pulsar\Api\Api;

use function array_pop;
use function dirname;
use function end;
use function getcwd;
use function implode;
use function is_dir;
use function is_file;
use function preg_match;
use function preg_split;
use function realpath;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

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
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SafePath
{
    /**
     * @param string $absolute Absolute path validated to live under boundary
     * @param string $boundary Realpath of the trust boundary the value object guarantees
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
     * Whether $candidate lies within (or is) $boundaryDir.
     *
     * Uses realpath + the nearest-existing-ancestor walk, so it defeats symlink
     * escapes and works for a path that does not exist yet (e.g. a var/cache
     * about to be created). Returns false when the boundary itself does not
     * exist. This is the correct primitive for a "is this path inside the
     * document root?" check — unlike str_starts_with on raw strings, it is not
     * bypassable via `..`/symlinks and does not false-match a sibling like
     * `public_html` against `public`.
     */
    public static function isWithin(string $candidate, string $boundaryDir): bool
    {
        $boundaryReal = realpath($boundaryDir);

        if ($boundaryReal === false) {
            // A boundary that does not exist yet is still a boundary. Containment is a
            // property of the intended tree, not of today's filesystem: `<base>/public`
            // is the document root whether or not the directory has been created, and a
            // cache configured inside it is unsafe either way.
            //
            // Returning false here meant "provably outside", which is what callers act
            // on — so a webroot not yet mounted (a container that binds it after boot, a
            // deployment mid-flight) silently permitted every path. Two very different
            // facts, "outside" and "cannot tell", shared one return value.
            //
            // Both sides are folded and compared textually. Mixing a realpath'd
            // candidate with a merely-folded boundary would disagree the moment any
            // parent is a symlink, which is the failure this class exists to prevent.
            return self::isWithinBoundary(
                self::foldTraversal($candidate),
                self::foldTraversal($boundaryDir),
            );
        }

        return self::verifyUnderBoundary($candidate, $boundaryReal) !== null;
    }

    /**
     * Verify $candidate resolves under $boundaryReal. Walks up to the
     * nearest existing ancestor (so paths-to-create can still be
     * validated) and uses realpath to defeat symlink escapes.
     */
    private static function verifyUnderBoundary(string $candidate, string $boundaryReal): ?string
    {
        // Collapse `..` before touching the filesystem. realpath() resolves `..` by
        // walking the tree, so on POSIX it returns false when any component along the
        // way is missing — `<base>/var/../public` fails outright while `var/` does not
        // exist yet. The ancestor walk below then climbs past `public` entirely, lands
        // on `<base>`, finds it outside the boundary and reports "not contained": the
        // containment check failed OPEN, on exactly the fresh-deployment state it
        // exists to police. Windows hid this for a year because its realpath() folds
        // `..` lexically and never needed the directory to exist.
        //
        // Folding first removes that dependency. The realpath() call still runs on the
        // result, so a symlink pointing out of the boundary is still caught on the part
        // of the path that does exist.
        $candidate = self::foldTraversal($candidate);
        $real = realpath($candidate);

        if ($real === false) {
            // Path doesn't exist yet — verify the nearest existing
            // ancestor still lives under boundaryReal.
            $ancestorReal = self::nearestExistingAncestor($candidate);

            if ($ancestorReal === false) {
                // Deliberately still a refusal. This `false` carries two meanings —
                // nothing along the path exists, and a regular file blocks it — and the
                // second must stay rejected: <file>/sub can never be created. Deciding
                // it lexically instead would report it containable. The boundary-missing
                // case that needed fixing is handled in isWithin(), where the two facts
                // are not entangled.
                return null;
            }
            if (!self::isWithinBoundary($ancestorReal, $boundaryReal)) {
                return null;
            }
            return $candidate;
        }

        if (!self::isWithinBoundary($real, $boundaryReal)) {
            return null;
        }

        return $real;
    }

    /**
     * Resolve `.` and `..` textually, without consulting the filesystem.
     *
     * Both separators are treated as one because a path assembled on Windows mixes
     * them freely (`C:\app\var/../public`), and a `..` that would climb above the root
     * is discarded rather than escaping it.
     */
    private static function foldTraversal(string $path): string
    {
        // Keep the root prefix aside: it is the one part that must not be folded.
        $root = '';

        if (preg_match('#^([A-Za-z]:[\\\\/]|\\\\\\\\|/)#', $path, $matches) === 1) {
            $root = $matches[1];
            $path = substr($path, strlen($root));
            $root = rtrim($root, '\\/') . DIRECTORY_SEPARATOR;
        }

        $segments = [];

        foreach (preg_split('#[\\\\/]+#', $path) ?: [] as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment !== '..') {
                $segments[] = $segment;
                continue;
            }

            // `..` past the last real segment is dropped: a relative path may legitimately
            // start with one, and an absolute path has nowhere above its root to go.
            if ($segments !== [] && end($segments) !== '..') {
                array_pop($segments);
                continue;
            }

            if ($root === '') {
                $segments[] = '..';
            }
        }

        return $root . implode(DIRECTORY_SEPARATOR, $segments);
    }

    /**
     * True when $real is the boundary itself or a descendant of it.
     *
     * A trailing separator is appended to the boundary before the
     * prefix test so a sibling directory sharing a name prefix
     * (`/app/data` vs boundary `/app`) is not mistaken for a child
     * (`/app` would otherwise prefix-match `/appdata`).
     */
    private static function isWithinBoundary(string $real, string $boundaryReal): bool
    {
        return $real === $boundaryReal
            || str_starts_with($real, $boundaryReal . DIRECTORY_SEPARATOR);
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
            // An existing regular file component means the path can
            // never be created (no directory can live under a file),
            // so the candidate must be rejected rather than walked
            // past as if the file segment did not exist.
            if (is_file($current)) {
                return false;
            }

            $parent = dirname($current);
            if ($parent === $current) {
                return false;
            }
            $current = $parent;
        }

        return $current === '' ? false : realpath($current);
    }
}
