<?php

declare(strict_types=1);

namespace Pulsar\I18n\Locale;

use NoDiscard;
use Pulsar\Api\Api;

use function ctype_alpha;
use function in_array;
use function ltrim;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

/**
 * Extracts, strips, and builds locale prefixes in URL paths.
 *
 * Operates on the first path segment to detect supported locale tags.
 * Uses string operations (not regex) on the hot path for performance.
 */
#[Api(since: '1.0.0')]
final readonly class UrlPrefixExtractor
{
    /**
     * Extract a supported locale from the URL path's first segment.
     *
     * Returns the matched locale string or null if no supported locale
     * is found. Rejects paths containing `..` segments for security.
     *
     * @param list<string> $supportedLocales
     */
    #[NoDiscard]
    public function extract(string $path, array $supportedLocales): ?string
    {
        if ($this->containsTraversal($path)) {
            return null;
        }

        $trimmed = ltrim($path, '/');

        if ($trimmed === '') {
            return null;
        }

        $slashPos = strpos($trimmed, '/');
        $segment = $slashPos !== false ? substr($trimmed, 0, $slashPos) : $trimmed;

        if (!$this->isLocaleShape($segment)) {
            return null;
        }

        if (!in_array($segment, $supportedLocales, true)) {
            return null;
        }

        return $segment;
    }

    /**
     * Strip the locale prefix from a path.
     *
     * Given `/fr/docs/intro` and `fr`, returns `/docs/intro`.
     * Given `/fr` and `fr`, returns `/`.
     * If the path does not start with the locale prefix, returns it as-is.
     */
    #[NoDiscard]
    public function stripPrefix(string $path, string $locale): string
    {
        $prefix = '/' . $locale;

        if ($path === $prefix) {
            return '/';
        }

        if (str_starts_with($path, $prefix . '/')) {
            $stripped = substr($path, strlen($prefix));

            // Prevent protocol-relative open redirect: /en//evil.com → //evil.com
            if (str_starts_with($stripped, '//')) {
                return '/' . ltrim($stripped, '/');
            }

            return $stripped;
        }

        return $path;
    }

    /**
     * Build a locale-prefixed path.
     *
     * When the locale equals the default and `$defaultLocaleInUrl` is false,
     * returns the plain content path. Otherwise prefixes with `/$locale`.
     */
    #[NoDiscard]
    public function buildPath(string $path, string $locale, string $defaultLocale, bool $defaultLocaleInUrl): string
    {
        // Normalize leading slash unconditionally so all branches return consistent paths
        $normalized = ($path === '' || $path === '/') ? '/' : (str_starts_with($path, '/') ? $path : '/' . $path);

        if ($locale === $defaultLocale && !$defaultLocaleInUrl) {
            return $normalized;
        }

        if ($normalized === '/') {
            return '/' . $locale;
        }

        return '/' . $locale . $normalized;
    }

    /**
     * Check for directory traversal sequences.
     *
     * Uses segment-based checks to avoid false positives on legitimate
     * paths containing `..` within segment names (e.g., `/fr/file..v2`).
     * Short-circuits on paths too short to contain a traversal pattern.
     */
    private function containsTraversal(string $path): bool
    {
        // Shortest traversal pattern is `/..` (3 chars) or `../` (3 chars)
        if (strlen($path) < 3) {
            return false;
        }

        // Mid-path: /foo/../bar — also catches root-leading /../bar
        // Trailing: /foo/..
        // Relative: ../foo (no leading slash)
        return str_contains($path, '/../')
            || str_ends_with($path, '/..')
            || str_starts_with($path, '../');
    }

    /**
     * Check if a segment has a valid locale shape (2-5 alpha chars, or xx-YY pattern).
     */
    private function isLocaleShape(string $segment): bool
    {
        $length = strlen($segment);

        if ($length < 2 || $length > 5) {
            return false;
        }

        if ($length === 2) {
            return ctype_alpha($segment);
        }

        return preg_match('/^[a-zA-Z]{2,3}-[a-zA-Z]{2}$/', $segment) === 1;
    }
}
