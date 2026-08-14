<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use NoDiscard;
use Pulsar\Api\Api;

use function array_keys;
use function explode;
use function fnmatch;
use function implode;
use function str_contains;
use function str_replace;

use const DIRECTORY_SEPARATOR;
use const FNM_PATHNAME;

/**
 * The set of files a manifest claims to cover.
 *
 * Both sides of the control read it from here: the builder to decide what to
 * hash, the verifier to decide what counts as an addition. Deriving those two
 * answers separately breaks the gate in both directions at once — it reports
 * files it never tracked as tampering, and it never looks into directories that
 * hold no entry, which is exactly where a dropped file goes. That is why the
 * walk lives once, behind {@see ManifestScopeWalkerInterface}, and not here.
 *
 * What remains is a value: two lists of patterns and the predicate over them.
 * It travels inside the signed manifest, so widening the exclusions invalidates
 * the signature rather than silently shrinking what is checked — and a value is
 * what can be signed, compared and reasoned about without asking the disk.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ManifestScope
{
    /**
     * @param list<string> $include Glob patterns for the files covered
     * @param list<string> $exclude Glob patterns carved back out of the includes
     */
    public function __construct(
        public array $include,
        public array $exclude,
    ) {}

    /**
     * The static directory prefix of each include pattern, deduplicated.
     *
     * A walker starts here rather than at the base path, so a scope covering
     * `src/**` never descends into vendor to discard the results afterwards.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function scanRoots(): array
    {
        $roots = [];

        foreach ($this->include as $pattern) {
            $roots[$this->extractBaseDir($pattern)] = true;
        }

        /** @var list<string> */
        return array_keys($roots);
    }

    /**
     * Whether a relative path falls inside this scope.
     *
     * Exclusions win: a path matching both an include and an exclude is out.
     */
    #[NoDiscard]
    public function covers(string $relativePath): bool
    {
        foreach ($this->exclude as $pattern) {
            if ($this->matchesGlob($relativePath, $pattern)) {
                return false;
            }
        }

        foreach ($this->include as $pattern) {
            if ($this->matchesGlob($relativePath, $pattern)) {
                return true;
            }
        }

        return false;
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
